"use strict";

const crypto = require("node:crypto");
const fs = require("node:fs");
const http = require("node:http");
const https = require("node:https");
const net = require("node:net");
const path = require("node:path");

const createMdns = require("multicast-dns");
const { AesTransport, TlsPskTransport } = require("./transport");
const { HomeConnectProtocol } = require("./protocol");
const {
  parseProfile,
  normalizeUid,
  entityFor,
  programsFor,
  humanize,
  valueFor,
} = require("./profile");

const HOME_CONNECT_SERVICE = "_homeconnect._tcp.local";
const LOG_FORMAT = "jeedom";
const REDISCOVERY_COOLDOWN_MS = 15000;
const MAX_PENDING_CALLBACKS = 64;
const LOG_LEVEL_VALUES = Object.freeze({
  debug: 100,
  info: 200,
  notice: 250,
  warning: 300,
  error: 400,
  critical: 500,
  alert: 550,
  emergency: 600,
  none: 1000,
});
let activeLogLevel = "info";

function timestamp() {
  return new Date().toISOString();
}

/** Formats a local date exactly like Jeedom's PHP logger. */
function formatLogTimestamp(date = new Date()) {
  const pad = value => String(value).padStart(2, "0");
  return [
    date.getFullYear(),
    pad(date.getMonth() + 1),
    pad(date.getDate()),
  ].join("-") + ` ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

function normalizeLogLevel(level) {
  const normalized = String(level || "").trim().toLowerCase();
  if (normalized === "warn") return "warning";
  return Object.hasOwn(LOG_LEVEL_VALUES, normalized) ? normalized : "info";
}

function configureLogging(level) {
  activeLogLevel = normalizeLogLevel(level);
  return activeLogLevel;
}

function shouldLog(level, configuredLevel = activeLogLevel) {
  const normalized = normalizeLogLevel(level);
  const threshold = LOG_LEVEL_VALUES[normalizeLogLevel(configuredLevel)];
  return threshold < LOG_LEVEL_VALUES.none && LOG_LEVEL_VALUES[normalized] >= threshold;
}

function formatLogLine(level, message, date = new Date()) {
  const normalized = normalizeLogLevel(level);
  return `[${formatLogTimestamp(date)}][${normalized.toUpperCase()}] ${String(message)}`;
}

function logger(level, message) {
  const normalized = normalizeLogLevel(level);
  if (!shouldLog(normalized)) return;
  process.stdout.write(`${formatLogLine(normalized, message)}\n`);
}

function parseArguments() {
  const option = process.argv.find(value => value.startsWith("--config="));
  if (!option) throw new Error("Le chemin --config est obligatoire");
  return option.slice("--config=".length);
}

function loadConfiguration() {
  const configPath = path.resolve(parseArguments());
  const stat = fs.statSync(configPath);
  if (!stat.isFile()) throw new Error("Configuration du démon introuvable");
  const config = JSON.parse(fs.readFileSync(configPath, "utf8"));
  if (!config.daemonToken || !config.callbackUrl || !Array.isArray(config.devices)) {
    throw new Error("Configuration du démon incomplète");
  }
  if (config.timezone) {
    try {
      new Intl.DateTimeFormat("fr-FR", { timeZone: String(config.timezone) }).format(new Date());
      process.env.TZ = String(config.timezone);
    } catch {
      // Le fuseau du système reste utilisé si la configuration PHP est invalide.
    }
  }
  return config;
}

/** Loads one private device profile without duplicating its secrets in daemon-config.json. */
function loadDeviceConfiguration(configuration) {
  if (!configuration || !configuration.profilePath) return configuration;
  const profilePath = path.resolve(String(configuration.profilePath));
  const profileDirectory = path.dirname(profilePath);
  const profile = JSON.parse(fs.readFileSync(profilePath, "utf8"));
  const schemaVersion = Number(profile.profileSchemaVersion || 0);
  if (!Number.isInteger(schemaVersion) || schemaVersion < 0 || schemaVersion > 1) {
    throw new Error(`Version de profil non prise en charge : ${profilePath}`);
  }
  if (!profile.haId || !profile.key) throw new Error(`Profil privé incomplet : ${profilePath}`);
  return {
    ...profile,
    ...configuration,
    deviceDescriptionPath: path.join(profileDirectory, "DeviceDescription.xml"),
    featureMappingPath: path.join(profileDirectory, "FeatureMapping.xml"),
  };
}

/** Writes the daemon PID atomically so Jeedom can stop exactly this process. */
function writePidFile(pidFile) {
  if (!pidFile) return;
  const target = path.resolve(String(pidFile));
  const temporary = `${target}.${process.pid}.tmp`;
  fs.writeFileSync(temporary, `${process.pid}\n`, { mode: 0o600 });
  fs.renameSync(temporary, target);
  fs.chmodSync(target, 0o600);
}

/** Removes the PID file only when it still belongs to the current process. */
function removePidFile(pidFile) {
  if (!pidFile) return;
  const target = path.resolve(String(pidFile));
  try {
    if (Number.parseInt(fs.readFileSync(target, "utf8"), 10) === process.pid) fs.unlinkSync(target);
  } catch (_) {
    // A missing or already replaced PID file needs no cleanup.
  }
}

function normalizedAccess(value) {
  return typeof value === "string" ? value.replace(/[^a-z]/gi, "").toLowerCase() : "";
}

function writableAccess(value) {
  return ["readwrite", "write"].includes(normalizedAccess(value));
}

function normalizeMac(value) {
  const normalized = String(value || "").toLowerCase().replace(/[^0-9a-f]/g, "");
  return normalized.length === 12 ? normalized : "";
}

function safeError(error) {
  return error instanceof Error ? error.message : String(error);
}

function delay(milliseconds) {
  return new Promise(resolve => setTimeout(resolve, Math.max(0, Number(milliseconds) || 0)));
}

function protocolValuesEqual(actual, expected) {
  if (typeof expected === "number" && Number.isFinite(expected)) return Number(actual) === expected;
  if (typeof expected === "boolean") {
    const normalized = typeof actual === "string" ? actual.toLowerCase() : actual;
    if ([true, 1, "1", "true", "on"].includes(normalized)) return expected === true;
    if ([false, 0, "0", "false", "off"].includes(normalized)) return expected === false;
    return false;
  }
  return String(actual) === String(expected);
}

function timingSafeToken(left, right) {
  const leftBuffer = Buffer.from(String(left || ""));
  const rightBuffer = Buffer.from(String(right || ""));
  return leftBuffer.length === rightBuffer.length && crypto.timingSafeEqual(leftBuffer, rightBuffer);
}

function postCallback(config, payload) {
  return new Promise((resolve, reject) => {
    const url = new URL(config.callbackUrl);
    const body = JSON.stringify(payload);
    const transport = url.protocol === "https:" ? https : http;
    const request = transport.request({
      protocol: url.protocol,
      hostname: url.hostname,
      port: url.port || undefined,
      path: `${url.pathname}${url.search}`,
      method: "POST",
      timeout: 15000,
      rejectUnauthorized: config.callbackVerifyTls !== false,
      headers: {
        "Content-Type": "application/json",
        "Content-Length": Buffer.byteLength(body),
        "X-Jeedom-ApiKey": config.apiKey,
      },
    }, response => {
      response.resume();
      response.on("end", () => {
        if ((response.statusCode || 500) >= 200 && (response.statusCode || 500) < 300) resolve();
        else reject(new Error(`Callback Jeedom HTTP ${response.statusCode}`));
      });
    });
    request.on("timeout", () => request.destroy(new Error("Callback Jeedom expiré")));
    request.on("error", reject);
    request.end(body);
  });
}

/** Maintains one appliance session and its live model. */
class ApplianceRuntime {
  constructor(manager, configuration) {
    this.manager = manager;
    this.configuration = loadDeviceConfiguration(configuration);
    this.haId = String(this.configuration.haId);
    this.host = String(this.configuration.host || "").trim();
    this.profile = parseProfile(this.configuration.deviceDescriptionPath, this.configuration.featureMappingPath);
    this.description = new Map();
    this.values = new Map();
    this.info = {};
    this.connected = false;
    this.connectionState = "offline";
    this.connecting = false;
    this.initializing = false;
    this.stopping = false;
    this.protocol = null;
    this.transportMode = "";
    this.connectedSince = null;
    this.reconnectTimer = null;
    this.reconnectFailures = 0;
    this.lastSeen = null;
    this.lastFrameAt = 0;
    this.lastError = "";
    this.selectedProgram = null;
    this.activeProgram = null;
    this.optionContextProgram = null;
    this.availablePrograms = new Map();
    this.schemaTimer = null;
    this.valueTimer = null;
    this.hostUpdatePromise = null;
    this.operationQueue = Promise.resolve();
    this.changedValueUids = new Set();
    this.advertisedEntityUids = new Set();
    this.lastSchemaAt = null;
    this.lastValueUpdateAt = null;
    for (const [uid, description] of Object.entries(this.profile.descriptionsByUid || {})) {
      this.description.set(uid, { ...description });
    }
    for (const program of programsFor(this.profile)) this.availablePrograms.set(program.uid, program);
  }

  async start() {
    if (!this.host) {
      await this.setStatus(false, "Adresse réseau inconnue, lancez la découverte mDNS");
      return;
    }
    await this.connect();
  }

  /** Serializes appliance I/O so refresh, DHCP migration and actions cannot overlap. */
  runOperation(label, operation) {
    const execute = async () => {
      if (this.stopping) throw new Error("Arrêt de l’appareil en cours");
      return operation();
    };
    const result = this.operationQueue.then(execute, execute);
    this.operationQueue = result.catch(error => {
      this.manager.debug(`${this.haId}: opération ${label} terminée en erreur : ${safeError(error)}`);
    });
    return result;
  }

  connectionModes() {
    return String(this.configuration.connectionType).toUpperCase() === "TLS"
      ? ["TLS", ...(this.configuration.iv ? ["AES"] : [])]
      : ["AES"];
  }

  createProtocol(host, mode, attachEvents = true) {
    const transport = mode === "TLS"
      ? new TlsPskTransport(host, this.configuration.key, this.manager.config.appId)
      : new AesTransport(host, this.configuration.key, this.configuration.iv);
    const protocol = new HomeConnectProtocol(transport, {
      appName: this.manager.config.appName,
      appId: this.manager.config.appId,
      logger: {
        debug: message => this.manager.debug(`${this.haId}: ${message}`),
        warn: message => logger("warning", `${this.haId}: ${message}`),
      },
    });
    protocol.on("error", error => logger("warning", `${this.haId}: ${safeError(error)}`));
    if (attachEvents) {
      protocol.on("message", message => this.handleMessage(message));
      protocol.on("close", () => {
        if (this.protocol === protocol) this.handleDisconnect();
      });
    }
    return protocol;
  }

  /** Authenticates a discovered address without replacing the active endpoint. */
  async probeHost(host) {
    let lastError = null;
    for (const mode of this.connectionModes()) {
      const protocol = this.createProtocol(host, mode, false);
      try {
        await protocol.connect(20000);
        await protocol.close().catch(() => undefined);
        return mode;
      } catch (error) {
        lastError = error;
        await protocol.close().catch(() => undefined);
      }
    }
    throw lastError || new Error("Adresse Home Connect non authentifiée");
  }

  async connect() {
    if (this.stopping) return false;
    if (this.connected && this.protocol) return true;
    if (this.connecting) return this.waitForConnection();
    this.connecting = true;
    this.initializing = true;
    this.connectionState = "connecting";
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    try {
      const modes = this.connectionModes();
      let lastError = null;
      for (const mode of modes) {
        let protocol = null;
        try {
          protocol = this.createProtocol(this.host, mode);
          await protocol.connect();
          this.protocol = protocol;
          this.transportMode = mode;
          if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
          }
          if (mode !== String(this.configuration.connectionType).toUpperCase()) {
            logger("warning", `${this.haId}: TLS indisponible, repli AES actif`);
          }
          lastError = null;
          break;
        } catch (error) {
          lastError = error;
          if (protocol) await protocol.close().catch(() => undefined);
          logger("warning", `${this.haId}: connexion ${mode} impossible : ${safeError(error)}`);
        }
      }
      if (lastError || !this.protocol) throw lastError || new Error("Aucun transport utilisable");
      this.reconnectFailures = 0;
      this.connectedSince = timestamp();
      await this.setStatus(true, "", "initializing");
      const initialReads = await this.protocol.readInitialValues();
      const unavailableResources = Object.entries(initialReads).filter(([, available]) => !available).map(([resource]) => resource);
      if (unavailableResources.length > 0) {
        this.manager.debug(`${this.haId}: ressources initiales facultatives indisponibles : ${unavailableResources.join(", ")}`);
      }
      this.initializing = false;
      await this.setStatus(true, "", "online");
      this.scheduleSchemaSnapshot();
      logger("info", `${this.haId}: connecté localement à ${this.host}`);
      return true;
    } catch (error) {
      const failedProtocol = this.protocol;
      this.protocol = null;
      if (failedProtocol) await failedProtocol.close().catch(() => undefined);
      this.initializing = false;
      this.transportMode = "";
      this.connectedSince = null;
      this.reconnectFailures += 1;
      await this.setStatus(false, safeError(error), "offline");
      this.manager.scheduleDiscovery(`${this.haId}: connexion impossible`);
      this.scheduleReconnect();
      return false;
    } finally {
      this.connecting = false;
    }
  }

  async stop() {
    this.stopping = true;
    if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
    if (this.schemaTimer) clearTimeout(this.schemaTimer);
    if (this.valueTimer) clearTimeout(this.valueTimer);
    await Promise.race([this.operationQueue, delay(3000)]).catch(() => undefined);
    if (this.protocol) await this.protocol.close().catch(() => undefined);
    this.protocol = null;
    this.connected = false;
    this.connectionState = "offline";
  }

  scheduleReconnect() {
    if (this.stopping || this.reconnectTimer) return;
    const base = Math.max(5, Number(this.manager.config.reconnectInterval || 30));
    const delay = Math.min(300, base * Math.max(1, 2 ** Math.min(this.reconnectFailures - 1, 4)));
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.runOperation("reconnexion", () => this.connect())
        .catch(error => logger("error", `${this.haId}: ${safeError(error)}`));
    }, delay * 1000);
  }

  handleDisconnect() {
    if (this.stopping) return;
    const previous = this.protocol;
    this.protocol = null;
    this.transportMode = "";
    this.connectedSince = null;
    if (previous) previous.close().catch(() => undefined);
    this.setStatus(false, "Connexion locale interrompue", "offline").catch(() => undefined);
    this.manager.scheduleDiscovery(`${this.haId}: connexion interrompue`);
    this.scheduleReconnect();
  }

  async setStatus(connected, error, state = connected ? "online" : "offline") {
    this.connected = Boolean(connected);
    this.connectionState = this.connected ? (state === "initializing" ? "initializing" : "online") : "offline";
    this.lastError = String(error || "");
    if (connected) {
      this.lastSeen = timestamp();
      this.lastFrameAt = Date.now();
    }
    await this.manager.callback({
      event: "status",
      haId: this.haId,
      host: this.host,
      connected: this.connected,
      state: this.connectionState,
      transport: this.transportMode,
      reconnectFailures: this.reconnectFailures,
      lastSeen: this.lastSeen,
      error: this.lastError,
    });
  }

  handleMessage(message) {
    this.lastSeen = timestamp();
    this.lastFrameAt = Date.now();
    const data = Array.isArray(message.data) ? message.data : [];
    const changedUids = new Set();
    let schemaChanged = false;
    let programStateChanged = false;
    if (["/ci/info", "/iz/info", "/ni/info"].includes(message.resource)) {
      Object.assign(this.info, data[0] || {});
    }
    if (["/ro/allDescriptionChanges", "/ro/descriptionChange"].includes(message.resource)) {
      schemaChanged = true;
      for (const item of data) {
        const uid = normalizeUid(item?.uid);
        if (uid) this.description.set(uid, { ...(this.description.get(uid) || {}), ...item });
      }
    }
    if (message.resource === "/ro/allMandatoryValues" && message.action === "RESPONSE") {
      this.values.clear();
    }
    if (String(message.resource || "").startsWith("/ro/")) {
      for (const item of data) {
        const uid = normalizeUid(item?.uid);
        if (uid && Object.prototype.hasOwnProperty.call(item, "value")) {
          this.values.set(uid, item.value);
          changedUids.add(uid);
          if (this.isOptionOfSelectedProgram(uid)) this.optionContextProgram = this.selectedProgram;
        }
      }
    }
    if (message.resource === "/ro/selectedProgram" && data[0]?.program !== undefined) {
      const selected = Number(data[0].program);
      if (selected !== this.selectedProgram) this.optionContextProgram = null;
      this.selectedProgram = selected;
      for (const uid of this.captureProgramOptions(data[0])) changedUids.add(uid);
      schemaChanged = true;
    }
    if (message.resource === "/ro/activeProgram" && data[0]?.program !== undefined) {
      this.activeProgram = Number(data[0].program);
      for (const uid of this.captureProgramOptions(data[0])) changedUids.add(uid);
      programStateChanged = true;
    }
    if (message.resource === "/ro/availablePrograms") {
      schemaChanged = true;
      for (const item of data) {
        const raw = Number(item?.program ?? item?.uid ?? item);
        if (!Number.isFinite(raw)) continue;
        const known = this.programForRaw(raw);
        this.availablePrograms.set(raw, known || { uid: raw, uidHex: normalizeUid(raw), feature: `uid_${normalizeUid(raw)}`, name: `Programme ${raw}` });
      }
    }
    if (this.initializing) return;
    if (schemaChanged || [...changedUids].some(uid => !this.advertisedEntityUids.has(uid))) {
      this.scheduleSchemaSnapshot();
      return;
    }
    if (changedUids.size > 0 || programStateChanged) this.scheduleValueUpdate(changedUids);
  }

  captureProgramOptions(item) {
    const captured = [];
    for (const option of Array.isArray(item?.options) ? item.options : []) {
      const uid = normalizeUid(option?.uid);
      if (uid && Object.prototype.hasOwnProperty.call(option, "value")) {
        this.values.set(uid, option.value);
        captured.push(uid);
      }
    }
    if (captured.length > 0 && Number.isFinite(Number(this.selectedProgram))) this.optionContextProgram = this.selectedProgram;
    return captured;
  }

  scheduleSchemaSnapshot() {
    if (this.initializing || this.schemaTimer) return;
    if (this.valueTimer) {
      clearTimeout(this.valueTimer);
      this.valueTimer = null;
    }
    this.schemaTimer = setTimeout(() => {
      this.schemaTimer = null;
      this.emitSchemaSnapshot().catch(error => logger("warning", `${this.haId}: callback structure : ${safeError(error)}`));
    }, 150);
  }

  scheduleValueUpdate(uids) {
    for (const uid of uids) this.changedValueUids.add(normalizeUid(uid));
    if (this.initializing || this.schemaTimer || this.valueTimer) return;
    this.valueTimer = setTimeout(() => {
      this.valueTimer = null;
      this.emitValueUpdate().catch(error => logger("warning", `${this.haId}: callback valeurs : ${safeError(error)}`));
    }, 100);
  }

  entityForUid(uid) {
    const normalized = normalizeUid(uid);
    if (!normalized || !this.values.has(normalized)) return null;
    if (this.isKnownProgramOption(normalized) && !this.isOptionOfSelectedProgram(normalized)) return null;
    const entity = entityFor(normalized, this.values.get(normalized), this.descriptionForUid(normalized), this.profile);
    if (!entity) return null;
    entity.writable = this.isWritable(entity.uidNumber) && !entity.dangerous;
    entity.requiresOptIn = entity.writable && !entity.safeWritable;
    return entity;
  }

  programStatePayload() {
    const selected = this.programForRaw(this.selectedProgram);
    const active = this.programForRaw(this.activeProgram);
    const selectedValid = this.selectedProgram !== null
      && this.selectedProgram !== undefined
      && Number.isFinite(Number(this.selectedProgram));
    const activeValid = this.activeProgram !== null
      && this.activeProgram !== undefined
      && Number.isFinite(Number(this.activeProgram));
    return {
      selectedProgram: selectedValid ? Number(this.selectedProgram) : null,
      activeProgram: activeValid ? Number(this.activeProgram) : null,
      selectedProgramName: selected?.feature || selected?.name || "",
      activeProgramName: active?.feature || active?.name || "",
    };
  }

  async emitValueUpdate() {
    const changed = [...this.changedValueUids];
    this.changedValueUids.clear();
    const entities = changed.map(uid => this.entityForUid(uid)).filter(Boolean);
    this.lastValueUpdateAt = timestamp();
    await this.manager.callback({
      event: "values",
      ...this.runtimeStatusPayload(),
      entities,
      ...this.programStatePayload(),
    });
  }

  async emitSchemaSnapshot() {
    if (this.valueTimer) {
      clearTimeout(this.valueTimer);
      this.valueTimer = null;
    }
    this.changedValueUids.clear();
    const entities = [];
    for (const [uid, rawValue] of this.values.entries()) {
      if (this.isKnownProgramOption(uid) && !this.isOptionOfSelectedProgram(uid)) continue;
      const description = this.descriptionForUid(uid);
      const entity = entityFor(uid, rawValue, description, this.profile);
      if (!entity) continue;
      entity.writable = this.isWritable(entity.uidNumber) && !entity.dangerous;
      entity.requiresOptIn = entity.writable && !entity.safeWritable;
      entities.push(entity);
    }
    for (const [uid, description] of this.description.entries()) {
      const feature = this.profile.featuresByUid[uid] || "";
      if (this.isKnownProgramOption(uid) && !this.isOptionOfSelectedProgram(uid)) continue;
      if (this.values.has(uid) || !this.isWritable(uid)) continue;
      // Le XML installé décrit les capacités stables de l'appareil. Une
      // propriété READWRITE doit rester annoncée même si sa valeur n'est pas
      // incluse dans l'instantané courant (porte, programme ou mode différent).
      // Les deux racines de programme disposent de commandes dédiées.
      if (["BSH.Common.Root.SelectedProgram", "BSH.Common.Root.ActiveProgram"].includes(feature)) continue;
      const isCommand = feature.includes(".Command.");
      const commandValue = description.default ?? description.initValue ?? null;
      const entity = entityFor(uid, commandValue, description, this.profile);
      if (!entity || entity.dangerous) continue;
      entity.writable = true;
      entity.requiresOptIn = !entity.safeWritable;
      entity.commandOnly = isCommand;
      entity.valueMissing = !isCommand;
      entities.push(entity);
    }
    entities.sort((left, right) => `${left.category}.${left.name}`.localeCompare(`${right.category}.${right.name}`));
    this.advertisedEntityUids = new Set(entities.map(entity => normalizeUid(entity.uid)));
    this.lastSchemaAt = timestamp();
    await this.manager.callback({
      event: "schema",
      ...this.runtimeStatusPayload(),
      complete: true,
      knownUids: Object.keys(this.profile.featuresByUid),
      writableUids: Object.keys(this.profile.featuresByUid).filter(uid =>
        writableAccess(this.profile.descriptionsByUid[uid]?.access)
        || this.profile.writableProgramOptionUids.has(uid)
        || this.isWritable(Number.parseInt(uid, 16))
      ),
      info: this.publicInfo(),
      entities,
      programs: [...this.availablePrograms.values()],
      selectedProgram: this.selectedProgram,
      activeProgram: this.activeProgram,
      canSelectProgram: this.featureWritable("BSH.Common.Root.SelectedProgram"),
      canStartProgram: this.featureWritable("BSH.Common.Root.ActiveProgram"),
    });
  }

  async emitSnapshot() {
    return this.emitSchemaSnapshot();
  }

  runtimeStatusPayload() {
    return {
      haId: this.haId,
      host: this.host,
      connected: this.connected,
      state: this.connectionState,
      transport: this.transportMode,
      reconnectFailures: this.reconnectFailures,
      lastSeen: this.lastSeen,
      error: this.lastError,
    };
  }

  publicInfo() {
    const allowed = ["deviceID", "eNumber", "brand", "vib", "mac", "haVersion", "swVersion", "hwVersion", "deviceType", "customerIndex", "serialNumber", "fdString"];
    return Object.fromEntries(allowed.filter(key => this.info[key] !== undefined).map(key => [key, this.info[key]]));
  }

  isWritable(uid) {
    const normalized = normalizeUid(uid);
    const liveDescription = this.description.get(normalized);
    const profileDescription = this.profile.descriptionsByUid[normalized];
    return Boolean(liveDescription && writableAccess(liveDescription.access))
      || Boolean(profileDescription && writableAccess(profileDescription.access))
      || this.isSelectedProgramOptionWritable(uid);
  }

  /** Returns the canonical XML definition completed by the selected program context. */
  descriptionForUid(uid) {
    const normalized = normalizeUid(uid);
    const description = { ...(this.description.get(normalized) || {}) };
    const programUid = normalizeUid(Number(this.selectedProgram));
    if (!programUid) return description;
    const option = (this.profile.programOptionsByUid[programUid] || [])
      .find(item => normalizeUid(item.refUID) === normalized);
    if (!option) return description;
    for (const key of ["access", "available", "default", "min", "max", "step"]) {
      if (option[key] !== undefined) description[key] = option[key];
    }
    return description;
  }

  isSelectedProgramOptionWritable(uid) {
    const programUid = normalizeUid(Number(this.selectedProgram));
    const targetUid = normalizeUid(uid);
    if (!programUid || !targetUid) return false;
    const feature = this.profile.featuresByUid[targetUid] || "";
    if ([
      "EnergyForecast", "WaterForecast", "ProgramProgress", "RemainingProgramTime",
      "RemainingProgramTimeIsEstimated", "EstimatedTotalProgramTime", "ElapsedProgramTime",
      "LoadRecommendation", "ProcessPhase", "ProgramName", "BaseProgram",
    ].some(marker => feature.endsWith(marker))) return false;
    return (this.profile.programOptionsByUid[programUid] || []).some(option =>
      normalizeUid(option.refUID) === targetUid
      && option.available !== false
      && writableAccess(option.access)
    );
  }

  isOptionOfSelectedProgram(uid) {
    const programUid = normalizeUid(Number(this.selectedProgram));
    const targetUid = normalizeUid(uid);
    if (!programUid || !targetUid) return false;
    return (this.profile.programOptionsByUid[programUid] || []).some(option => normalizeUid(option.refUID) === targetUid);
  }

  isKnownProgramOption(uid) {
    const targetUid = normalizeUid(uid);
    return Boolean(targetUid && this.profile.knownProgramOptionUids.has(targetUid));
  }

  selectedProgramOptions() {
    const programUid = normalizeUid(Number(this.selectedProgram));
    if (!programUid || Number(this.optionContextProgram) !== Number(this.selectedProgram)) return [];
    const values = [];
    for (const option of this.profile.programOptionsByUid[programUid] || []) {
      const uid = normalizeUid(option.refUID);
      if (!uid || !this.isSelectedProgramOptionWritable(uid) || !this.values.has(uid)) continue;
      const value = this.values.get(uid);
      if (value === undefined || value === null) continue;
      values.push({ uid: Number.parseInt(uid, 16), value });
    }
    return values;
  }

  featureValue(featureName) {
    const uid = this.profile.featureUidByName[featureName];
    return uid ? this.values.get(uid) : undefined;
  }

  featureWritable(featureName) {
    const uid = this.profile.featureUidByName[featureName];
    return Boolean(uid && this.isWritable(Number.parseInt(uid, 16)));
  }

  featureDisplayValue(featureName) {
    const uid = this.profile.featureUidByName[featureName];
    if (!uid || !this.values.has(uid)) return undefined;
    return valueFor(this.values.get(uid), uid, this.profile);
  }

  /** Returns whether the authenticated Home Connect protocol is operational. */
  sessionIsReady() {
    return Boolean(this.protocol && this.protocol.connected);
  }

  /** Waits for an already-started asynchronous connection attempt. */
  async waitForConnection(timeoutMs = 20000) {
    const deadline = Date.now() + timeoutMs;
    while (!this.connected && this.connecting && Date.now() < deadline) {
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    return this.connected && Boolean(this.protocol);
  }

  programForRaw(raw) {
    if (raw === null || raw === undefined || !Number.isFinite(Number(raw))) return null;
    const uid = normalizeUid(Number(raw));
    const feature = this.profile.featuresByUid[uid];
    return feature ? { uid: Number(raw), uidHex: uid, feature, name: humanize(feature) } : null;
  }

  startBlockedReason(program) {
    if (!this.connected || !this.protocol) return "Appareil hors ligne";
    const door = String(this.featureDisplayValue("BSH.Common.Status.DoorState") ?? "").toLowerCase();
    if (door === "open" || door.endsWith(".open") || door === "ajar") return "Porte ouverte";
    const operation = String(this.featureDisplayValue("BSH.Common.Status.OperationState") ?? "").toLowerCase();
    if (["run", "running"].includes(operation)) return "Un programme est déjà en cours";
    const remote = this.featureValue("BSH.Common.Status.RemoteControlStartAllowed");
    if (remote !== undefined && ![true, 1, "1", "true", "on"].includes(typeof remote === "string" ? remote.toLowerCase() : remote)) {
      return "Démarrage à distance non autorisé par l’appareil";
    }
    const power = String(this.featureDisplayValue("BSH.Common.Setting.PowerState") ?? "").toLowerCase();
    if (["off", "mainsoff", "false"].includes(power)) return "L’appareil est éteint";
    if (!Number.isFinite(Number(program))) return "Aucun programme sélectionné";
    if (!this.availablePrograms.has(Number(program))) return "Programme inconnu du profil de l’appareil";
    if (!this.featureWritable("BSH.Common.Root.ActiveProgram")) return "Le démarrage n’est pas autorisé dans l’état actuel de l’appareil";
    return "";
  }

  async refresh() {
    if (this.connecting) {
      if (await this.waitForConnection()) {
        return;
      }
    }
    if (!this.protocol || !this.connected) {
      await this.connect();
      return;
    }
    this.initializing = true;
    try {
      await this.setStatus(true, "", "online");
      const initialReads = await this.protocol.readInitialValues();
      const unavailableResources = Object.entries(initialReads).filter(([, available]) => !available).map(([resource]) => resource);
      if (unavailableResources.length > 0) {
        this.manager.debug(`${this.haId}: ressources initiales facultatives indisponibles : ${unavailableResources.join(", ")}`);
      }
      await this.setStatus(true, "", "online");
    } catch (error) {
      const sessionAlive = Boolean(this.protocol?.connected && !this.protocol?.transport?.closed);
      if (sessionAlive) {
        await this.setStatus(true, safeError(error), "online");
      } else {
        this.handleDisconnect();
      }
      throw error;
    } finally {
      this.initializing = false;
      if (this.connected) this.scheduleSchemaSnapshot();
    }
  }

  async heartbeat() {
    if (!this.protocol || !this.connected) return;
    await this.protocol.heartbeat();
    this.lastFrameAt = Date.now();
  }

  validateWriteValue(uid, value, allowUnknown = false) {
    const normalized = normalizeUid(uid);
    const feature = this.profile.featuresByUid[normalized] || `UID ${normalized}`;
    if (!normalized || !this.isWritable(uid)) throw new Error(`La propriété ${feature} n’est plus accessible en écriture`);
    const description = this.descriptionForUid(normalized);
    if (description.available === false || String(description.available).toLowerCase() === "false") {
      throw new Error(`La propriété ${feature} n’est pas disponible dans l’état actuel`);
    }
    if (value === undefined || value === null || (typeof value === "number" && !Number.isFinite(value))) {
      throw new Error(`La valeur de ${feature} est absente ou invalide`);
    }
    const entity = entityFor(uid, value, description, this.profile);
    if (!entity || entity.dangerous) throw new Error("Cette commande sensible est bloquée par LocalHomeConnect");
    if (!entity.safeWritable && !allowUnknown) {
      throw new Error("Cette commande XML inconnue doit être autorisée manuellement dans Jeedom");
    }
    const metadata = entity.metadata || {};
    const states = metadata.states || {};
    const protocolType = String(metadata.protocolType || "").toLowerCase();
    const stateKey = protocolType === "boolean" ? (value ? "1" : "0") : String(value);
    if (Object.keys(states).length > 0 && !Object.hasOwn(states, stateKey)) {
      throw new Error(`Valeur non autorisée pour ${feature}`);
    }
    if (protocolType === "boolean" && typeof value !== "boolean") {
      throw new Error(`La propriété ${feature} attend un booléen`);
    }
    if (protocolType === "integer" && (!Number.isFinite(value) || !Number.isInteger(value))) {
      throw new Error(`La propriété ${feature} attend un nombre entier`);
    }
    if (["number", "float", "double"].includes(protocolType) && !Number.isFinite(value)) {
      throw new Error(`La propriété ${feature} attend un nombre`);
    }
    if (protocolType === "string" && typeof value !== "string") {
      throw new Error(`La propriété ${feature} attend un texte`);
    }
    if (["integer", "number", "float", "double"].includes(protocolType)) {
      const numeric = Number(value);
      const minimum = Number(metadata.min);
      const maximum = Number(metadata.max);
      const step = Number(metadata.step);
      if (Number.isFinite(minimum) && numeric < minimum) throw new Error(`Valeur inférieure au minimum ${minimum} pour ${feature}`);
      if (Number.isFinite(maximum) && numeric > maximum) throw new Error(`Valeur supérieure au maximum ${maximum} pour ${feature}`);
      if (Number.isFinite(step) && step > 0) {
        const base = Number.isFinite(minimum) ? minimum : 0;
        const quotient = (numeric - base) / step;
        if (Math.abs(quotient - Math.round(quotient)) > 1e-7) {
          throw new Error(`La valeur de ${feature} doit respecter le pas ${step}`);
        }
      }
    }
    return { normalized, feature };
  }

  async execute(command) {
    if (!this.protocol || !this.connected) throw new Error("Appareil hors ligne");
    let pendingConfirmation = false;
    if (command.type === "write") {
      const uid = Number(command.uid);
      const { normalized } = this.validateWriteValue(uid, command.value, command.allowUnknown === true);
      await this.protocol.writeValue(uid, command.value);
      await delay(300);
      try {
        const confirmation = await this.protocol.readValues([uid]);
        const confirmedValue = (Array.isArray(confirmation?.data) ? confirmation.data : [])
          .find(item => Number(item?.uid) === uid)?.value;
        pendingConfirmation = confirmedValue === undefined || !protocolValuesEqual(confirmedValue, command.value);
      } catch (error) {
        pendingConfirmation = true;
        this.manager.debug(`${this.haId}: relecture de ${normalized} indisponible : ${safeError(error)}`);
      }
    } else if (command.type === "select_program") {
      const program = Number(command.program);
      if (!Number.isFinite(program)) throw new Error("Programme invalide");
      if (!this.availablePrograms.has(program)) throw new Error("Programme inconnu du profil de l’appareil");
      if (!this.featureWritable("BSH.Common.Root.SelectedProgram")) {
        throw new Error("La sélection de programme n’est pas autorisée dans l’état actuel de l’appareil");
      }
      const response = await this.protocol.selectProgram(program, []);
      for (const uid of this.captureProgramOptions(Array.isArray(response?.data) ? response.data[0] : null)) this.changedValueUids.add(uid);
      await delay(300);
      try {
        const confirmed = await this.protocol.optionalGet("/ro/selectedProgram", 10000);
        const confirmedProgram = Array.isArray(confirmed?.data) ? Number(confirmed.data[0]?.program) : NaN;
        pendingConfirmation = confirmed === null || confirmedProgram !== program;
      } catch (error) {
        pendingConfirmation = true;
        this.manager.debug(`${this.haId}: confirmation du programme sélectionné indisponible : ${safeError(error)}`);
      }
    } else if (command.type === "start_program") {
      const program = Number(command.program ?? this.selectedProgram);
      const blocked = this.startBlockedReason(program);
      if (blocked) throw new Error(blocked);
      const options = Array.isArray(command.options) ? command.options : this.selectedProgramOptions();
      const response = await this.protocol.startProgram(program, options);
      for (const uid of this.captureProgramOptions(Array.isArray(response?.data) ? response.data[0] : null)) this.changedValueUids.add(uid);
      await delay(300);
      try {
        const confirmed = await this.protocol.optionalGet("/ro/activeProgram", 10000);
        const confirmedProgram = Array.isArray(confirmed?.data) ? Number(confirmed.data[0]?.program) : NaN;
        pendingConfirmation = confirmed === null || confirmedProgram !== program;
      } catch (error) {
        pendingConfirmation = true;
        this.manager.debug(`${this.haId}: confirmation du programme actif indisponible : ${safeError(error)}`);
      }
    } else {
      throw new Error("Type de commande inconnu");
    }
    if (this.changedValueUids.size > 0) this.scheduleValueUpdate(this.changedValueUids);
    if (pendingConfirmation) {
      logger("warning", `${this.haId}: commande acquittée, confirmation de la valeur encore en attente`);
    }
    this.lastSeen = timestamp();
    this.lastFrameAt = Date.now();
    return { success: true, pendingConfirmation };
  }

  async updateHost(host) {
    const normalized = String(host || "").trim();
    if (!normalized || normalized === this.host) return false;
    if (this.hostUpdatePromise) return this.hostUpdatePromise;
    this.hostUpdatePromise = (async () => {
      await this.probeHost(normalized);
      const previousHost = this.host;
      const previous = this.protocol;
      this.protocol = null;
      if (previous) await previous.close().catch(() => undefined);
      this.connected = false;
      this.connectionState = "offline";
      this.host = normalized;
      this.configuration.host = normalized;
      if (await this.connect()) return true;

      this.host = previousHost;
      this.configuration.host = previousHost;
      logger("warning", `${this.haId}: adresse ${normalized} authentifiée mais connexion durable impossible, retour à ${previousHost}`);
      if (previousHost) await this.connect();
      throw new Error(`La nouvelle adresse ${normalized} n’a pas pu être activée`);
    })();
    try {
      return await this.hostUpdatePromise;
    } finally {
      this.hostUpdatePromise = null;
    }
  }

  health() {
    return {
      haId: this.haId,
      host: this.host,
      connected: this.connected,
      state: this.connected ? "online" : "offline",
      connecting: this.connecting,
      transport: this.transportMode,
      connectedSince: this.connectedSince,
      lastSeen: this.lastSeen,
      lastSchemaAt: this.lastSchemaAt,
      lastValueUpdateAt: this.lastValueUpdateAt,
      lastError: this.lastError,
      reconnectFailures: this.reconnectFailures,
      states: this.values.size,
      writable: [...this.description.values()].filter(item => writableAccess(item.access)).length,
      entities: this.advertisedEntityUids.size,
    };
  }
}

/** Coordinates appliance runtimes, HTTP control and Jeedom callbacks. */
class DaemonManager {
  constructor(config) {
    this.config = {
      ...config,
      logLevel: configureLogging(config.logLevel),
      debugProtocol: config.debugProtocol === true || config.debugProtocol === 1 || config.debug === true,
    };
    this.devices = new Map();
    this.server = null;
    this.stopping = false;
    this.pendingCallbacks = new Map();
    this.callbackWorker = null;
    this.callbackFailures = 0;
    this.callbackUnavailable = false;
    this.callbackShutdownDeadline = 0;
    this.callbackRecoveryTimer = null;
    this.fatalHandler = null;
    this.startedAt = timestamp();
    this.discoveryPromise = null;
    this.rediscoveryTimer = null;
    this.lastDiscoveryStartedAt = 0;
    for (const device of config.devices) {
      try {
        const runtime = new ApplianceRuntime(this, device);
        this.devices.set(runtime.haId, runtime);
      } catch (error) {
        logger("error", `${device.haId || "profil"}: profil illisible : ${safeError(error)}`);
      }
    }
    if (config.devices.length > 0 && this.devices.size === 0) {
      throw new Error("Aucun profil Home Connect valide ne peut être chargé");
    }
  }

  debug(message) {
    if (this.config.debugProtocol) logger("debug", message);
  }

  updateLogging(configuration = {}) {
    if (Object.hasOwn(configuration, "logLevel")) {
      this.config.logLevel = configureLogging(configuration.logLevel);
    }
    if (Object.hasOwn(configuration, "debugProtocol")) {
      this.config.debugProtocol = configuration.debugProtocol === true || configuration.debugProtocol === 1;
    }
    return {
      logLevel: this.config.logLevel,
      debugProtocol: this.config.debugProtocol,
    };
  }

  callback(payload) {
    const event = String(payload?.event || "event");
    const haId = String(payload?.haId || "global");
    const key = `${event}:${haId}`;
    let nextPayload = payload;
    const previous = this.pendingCallbacks.get(key);
    if (event === "values" && previous) {
      const entities = new Map();
      for (const entity of [...(previous.entities || []), ...(payload.entities || [])]) {
        entities.set(String(entity?.uid || entities.size), entity);
      }
      nextPayload = { ...previous, ...payload, entities: [...entities.values()] };
    }
    if (event === "schema") this.pendingCallbacks.delete(`values:${haId}`);
    this.pendingCallbacks.delete(key);
    this.pendingCallbacks.set(key, nextPayload);
    while (this.pendingCallbacks.size > MAX_PENDING_CALLBACKS) {
      const disposable = [...this.pendingCallbacks.keys()].find(item => item.startsWith("values:"))
        || this.pendingCallbacks.keys().next().value;
      this.pendingCallbacks.delete(disposable);
      logger("warning", `File de callbacks pleine : événement ${disposable} regroupé ou abandonné`);
    }
    this.startCallbackWorker();
    return Promise.resolve({ queued: true });
  }

  startCallbackWorker() {
    if (this.callbackWorker || this.stopping) return;
    this.callbackWorker = this.drainCallbacks()
      .catch(error => logger("error", `Traitement des callbacks : ${safeError(error)}`))
      .finally(() => {
        this.callbackWorker = null;
        if (this.pendingCallbacks.size > 0 && !this.stopping) this.startCallbackWorker();
      });
  }

  async drainCallbacks() {
    while (this.pendingCallbacks.size > 0) {
      if (this.stopping && this.callbackShutdownDeadline > 0 && Date.now() >= this.callbackShutdownDeadline) break;
      const [key, payload] = this.pendingCallbacks.entries().next().value;
      this.pendingCallbacks.delete(key);
      let lastError = null;
      const retryDeadline = this.stopping
        ? this.callbackShutdownDeadline
        : Date.now() + Math.max(30000, Number(this.config.callbackRetryDuration || 120) * 1000);
      for (let attempt = 0; Date.now() < retryDeadline; attempt += 1) {
        try {
          await postCallback(this.config, payload);
          lastError = null;
          if (this.callbackUnavailable) {
            this.callbackUnavailable = false;
            clearTimeout(this.callbackRecoveryTimer);
            this.callbackRecoveryTimer = null;
            for (const device of this.devices.values()) {
              if (device.connected) device.scheduleSchemaSnapshot();
            }
          }
          break;
        } catch (error) {
          lastError = error;
          this.callbackUnavailable = true;
          const pause = Math.min(10000, 250 * (2 ** Math.min(attempt, 6)), Math.max(0, retryDeadline - Date.now()));
          if (pause > 0) await delay(pause);
        }
      }
      if (lastError) {
        this.callbackFailures += 1;
        logger("warning", `Callback Jeedom ${key} abandonné après expiration du délai : ${safeError(lastError)}`);
        this.scheduleCallbackRecovery();
      }
    }
  }

  /** Recrée un instantané complet après une indisponibilité prolongée de Jeedom. */
  scheduleCallbackRecovery() {
    if (this.stopping || this.callbackRecoveryTimer) return;
    this.callbackRecoveryTimer = setTimeout(() => {
      this.callbackRecoveryTimer = null;
      for (const device of this.devices.values()) {
        if (device.connected) device.scheduleSchemaSnapshot();
      }
    }, 30000);
  }

  async start() {
    await this.startHttpServer();
    writePidFile(this.config.pidFile);
    for (const device of this.devices.values()) {
      device.runOperation("démarrage", () => device.start())
        .catch(error => logger("warning", `${device.haId}: ${safeError(error)}`));
    }
    this.discover().catch(error => logger("warning", `Découverte initiale : ${safeError(error)}`));
    const watchdogSeconds = Math.max(60, Number(this.config.watchdogInterval || 600));
    this.watchdogTimer = setInterval(() => this.watchdog(), Math.min(60, watchdogSeconds) * 1000);
    this.discoveryTimer = setInterval(() => this.discover().catch(() => undefined), 15 * 60 * 1000);
  }

  startHttpServer() {
    const port = Number(this.config.daemonPort || 55043);
    this.server = http.createServer((request, response) => this.handleHttp(request, response));
    return new Promise((resolve, reject) => {
      const initialError = error => reject(error);
      this.server.once("error", initialError);
      this.server.listen(port, "127.0.0.1", () => {
        this.server.off("error", initialError);
        this.server.on("error", error => {
          if (this.fatalHandler) this.fatalHandler(error);
          else logger("error", `Serveur du démon : ${safeError(error)}`);
        });
        logger("info", `Démon disponible sur 127.0.0.1:${port}`);
        resolve();
      });
    });
  }

  async handleHttp(request, response) {
    response.setHeader("Content-Type", "application/json; charset=utf-8");
    if (!timingSafeToken(request.headers.authorization?.replace(/^Bearer\s+/i, ""), this.config.daemonToken)) {
      response.statusCode = 403;
      response.end(JSON.stringify({ success: false, error: "Accès refusé" }));
      return;
    }
    try {
      if (request.method === "GET" && request.url === "/health") {
        const memory = process.memoryUsage();
        response.end(JSON.stringify({
          success: true,
          pid: process.pid,
          nodeVersion: process.version,
          startedAt: this.startedAt,
          uptimeSeconds: Math.round(process.uptime()),
          memoryRssMb: Math.round(memory.rss / 1048576),
          logFormat: LOG_FORMAT,
          logLevel: this.config.logLevel,
          debugProtocol: this.config.debugProtocol,
          callbackQueueDepth: this.pendingCallbacks.size,
          callbackFailures: this.callbackFailures,
          devices: [...this.devices.values()].map(device => device.health()),
        }));
        return;
      }
      const body = await readJsonBody(request);
      if (request.method === "POST" && request.url === "/logging") {
        response.end(JSON.stringify({ success: true, ...this.updateLogging(body) }));
        return;
      }
      if (request.method === "POST" && request.url === "/discover") {
        response.end(JSON.stringify({ success: true, devices: await this.discover() }));
        return;
      }
      const device = this.devices.get(String(body.haId || ""));
      if (!device) throw new Error("Appareil inconnu du démon");
      if (request.method === "POST" && request.url === "/refresh") {
        await device.runOperation("rafraîchissement", async () => {
          const hostChanged = body.host ? await device.updateHost(body.host) : false;
          if (!hostChanged) await device.refresh();
          if (!device.connected) throw new Error(device.lastError || "Appareil hors ligne");
        });
        response.end(JSON.stringify({ success: true, health: device.health() }));
        return;
      }
      if (request.method === "POST" && request.url === "/command") {
        const result = await device.runOperation("commande", () => device.execute(body));
        response.end(JSON.stringify({ ...result, health: device.health() }));
        return;
      }
      response.statusCode = 404;
      response.end(JSON.stringify({ success: false, error: "Route inconnue" }));
    } catch (error) {
      response.statusCode = 409;
      response.end(JSON.stringify({ success: false, error: safeError(error) }));
    }
  }

  async watchdog() {
    const idleLimit = Math.max(60, Number(this.config.watchdogInterval || 600)) * 1000;
    for (const device of this.devices.values()) {
      if (!device.connected || Date.now() - device.lastFrameAt < idleLimit) continue;
      try {
        await device.runOperation("surveillance", () => device.heartbeat());
      } catch (error) {
        logger("warning", `${device.haId}: surveillance : ${safeError(error)}`);
        device.handleDisconnect();
      }
    }
  }

  /**
   * Programme une nouvelle découverte après une perte de connexion.
   *
   * Les demandes de plusieurs appareils sont regroupées et un délai minimal
   * évite de saturer le réseau en requêtes mDNS pendant une panne générale.
   */
  scheduleDiscovery(reason = "") {
    if (this.stopping || this.rediscoveryTimer) return;
    const cooldown = Math.max(0, REDISCOVERY_COOLDOWN_MS - (Date.now() - this.lastDiscoveryStartedAt));
    const delay = Math.max(1000, cooldown);
    if (reason) this.debug(`${reason}, redécouverte mDNS programmée`);
    this.rediscoveryTimer = setTimeout(() => {
      this.rediscoveryTimer = null;
      this.discover().catch(error => logger("warning", `Redécouverte mDNS : ${safeError(error)}`));
    }, delay);
  }

  async discover() {
    if (this.discoveryPromise) return this.discoveryPromise;
    const discovery = this.performDiscovery();
    this.discoveryPromise = discovery;
    try {
      return await discovery;
    } finally {
      if (this.discoveryPromise === discovery) this.discoveryPromise = null;
    }
  }

  async performDiscovery() {
    this.lastDiscoveryStartedAt = Date.now();
    const timeout = Math.max(2, Math.min(30, Number(this.config.discoveryTimeout || 8)));
    const found = await discoverHomeConnect(timeout);
    const candidates = new Map();
    for (const discovery of found) {
      const runtime = this.matchDiscovery(discovery);
      if (!runtime) continue;
      if (!candidates.has(runtime.haId)) candidates.set(runtime.haId, { runtime, discoveries: [] });
      candidates.get(runtime.haId).discoveries.push(discovery);
    }
    await mapWithConcurrency([...candidates.values()], 3, async ({ runtime, discoveries }) => {
      const addresses = [...new Set(discoveries.flatMap(discovery => [
        ...(Array.isArray(discovery.addresses) ? discovery.addresses : []),
        discovery.address,
        discovery.host,
      ]).filter(Boolean))];
      let lastError = null;
      for (const address of addresses) {
        const previousHost = runtime.host;
        try {
          const changed = await runtime.runOperation("adresse DHCP", () => runtime.updateHost(address));
          if (changed) {
            logger("info", `${runtime.haId}: adresse authentifiée et mise à jour par mDNS (${previousHost || "inconnue"} → ${address})`);
            await this.callback({ event: "discovery", haId: runtime.haId, host: address, previousHost, discovery: discoveries[0] });
          }
          return;
        } catch (error) {
          lastError = error;
          this.debug(`${runtime.haId}: adresse mDNS ${address} ignorée : ${safeError(error)}`);
        }
      }
      if (lastError) logger("warning", `${runtime.haId}: aucune adresse mDNS authentifiée : ${safeError(lastError)}`);
    });
    return found;
  }

  matchDiscovery(discovery) {
    if (discovery.id && this.devices.has(discovery.id)) return this.devices.get(discovery.id);
    const mac = normalizeMac(discovery.mac);
    if (mac) {
      const matched = [...this.devices.values()].find(device => normalizeMac(device.configuration.mac) === mac);
      if (matched) return matched;
    }
    const candidates = [...this.devices.values()].filter(device =>
      String(device.configuration.brand || "").toLowerCase() === String(discovery.brand || "").toLowerCase()
      && String(device.configuration.type || "").toLowerCase() === String(discovery.type || "").toLowerCase()
      && String(device.configuration.vib || "").toLowerCase() === String(discovery.vib || "").toLowerCase()
    );
    return candidates.length === 1 ? candidates[0] : null;
  }

  async stop() {
    if (this.stopping) return;
    this.stopping = true;
    clearInterval(this.watchdogTimer);
    clearInterval(this.discoveryTimer);
    clearTimeout(this.rediscoveryTimer);
    clearTimeout(this.callbackRecoveryTimer);
    await Promise.all([...this.devices.values()].map(device => device.stop()));
    this.callbackShutdownDeadline = Date.now() + 3000;
    if (!this.callbackWorker && this.pendingCallbacks.size > 0) {
      this.callbackWorker = this.drainCallbacks().finally(() => { this.callbackWorker = null; });
    }
    if (this.callbackWorker) await Promise.race([this.callbackWorker, delay(3000)]).catch(() => undefined);
    if (this.server?.listening) await new Promise(resolve => this.server.close(resolve));
    removePidFile(this.config.pidFile);
  }
}

function readJsonBody(request) {
  return new Promise((resolve, reject) => {
    let body = "";
    request.setEncoding("utf8");
    request.on("data", chunk => {
      body += chunk;
      if (body.length > 1048576) request.destroy(new Error("Requête trop volumineuse"));
    });
    request.on("end", () => {
      try { resolve(body ? JSON.parse(body) : {}); }
      catch (_) { reject(new Error("JSON de commande invalide")); }
    });
    request.on("error", reject);
  });
}

function normalizeDnsName(value) {
  return String(value || "").trim().toLowerCase().replace(/\.$/, "");
}

function parseTxt(value) {
  const result = {};
  for (const entry of Array.isArray(value) ? value : []) {
    const text = Buffer.isBuffer(entry) ? entry.toString("utf8") : String(entry);
    const separator = text.indexOf("=");
    if (separator > 0) result[text.slice(0, separator)] = text.slice(separator + 1);
  }
  return result;
}

async function mapWithConcurrency(items, concurrency, worker) {
  const queue = [...items];
  const runners = Array.from({ length: Math.min(Math.max(1, concurrency), queue.length) }, async () => {
    while (queue.length > 0) await worker(queue.shift());
  });
  await Promise.all(runners);
}

function rankAddress(address) {
  const value = String(address || "").trim();
  const family = net.isIP(value);
  if (family === 4) return 0;
  if (family === 6 && !/^fe[89ab][0-9a-f]:/i.test(value)) return 1;
  if (family === 0 && value !== "") return 2;
  return 3;
}

function devicesFromRecords(records) {
  const service = normalizeDnsName(HOME_CONNECT_SERVICE);
  const serviceNames = new Set();
  for (const record of records) {
    const recordName = normalizeDnsName(record.name);
    if (record.type === "PTR" && recordName === service && typeof record.data === "string") serviceNames.add(normalizeDnsName(record.data));
    if (["SRV", "TXT"].includes(record.type) && recordName.endsWith(`.${service}`)) serviceNames.add(recordName);
  }
  const result = [];
  for (const serviceName of serviceNames) {
    const srv = records.find(record => record.type === "SRV" && normalizeDnsName(record.name) === serviceName);
    const target = normalizeDnsName(srv?.data?.target);
    const addresses = [...new Set(records
      .filter(record => ["A", "AAAA"].includes(record.type) && normalizeDnsName(record.name) === target)
      .map(record => String(record.data || "").trim())
      .filter(Boolean))].sort((left, right) => rankAddress(left) - rankAddress(right));
    const txt = parseTxt(records.find(record => record.type === "TXT" && normalizeDnsName(record.name) === serviceName)?.data);
    result.push({
      id: txt.id,
      name: serviceName.slice(0, -(service.length + 1)),
      host: target,
      address: addresses[0],
      addresses,
      port: Number(srv?.data?.port || 0),
      brand: txt.brand,
      type: txt.type,
      vib: txt.vib,
      mac: txt.mac,
    });
  }
  return result;
}

function discoverHomeConnect(timeoutSeconds) {
  return new Promise((resolve, reject) => {
    const mdns = createMdns();
    const records = [];
    let done = false;
    const finish = () => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      mdns.destroy(() => resolve(devicesFromRecords(records)));
    };
    const timer = setTimeout(finish, timeoutSeconds * 1000);
    mdns.on("response", response => records.push(...(response.answers || []), ...(response.additionals || []), ...(response.authorities || [])));
    mdns.on("error", error => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      mdns.destroy();
      reject(error);
    });
    mdns.query({ questions: [{ name: HOME_CONNECT_SERVICE, type: "PTR" }] });
  });
}

async function main() {
  const manager = new DaemonManager(loadConfiguration());
  let shuttingDown = false;
  const shutdown = async (signal, exitCode = 0) => {
    if (shuttingDown) return;
    shuttingDown = true;
    logger("info", `Arrêt demandé (${signal})`);
    try { await manager.stop(); }
    finally { process.exit(exitCode); }
  };
  const fatal = error => {
    logger("error", `Erreur fatale : ${safeError(error)}`);
    shutdown("erreur fatale", 1).catch(() => process.exit(1));
  };
  manager.fatalHandler = fatal;
  process.on("SIGTERM", () => shutdown("SIGTERM"));
  process.on("SIGINT", () => shutdown("SIGINT"));
  process.on("uncaughtException", fatal);
  process.on("unhandledRejection", fatal);
  await manager.start();
}

if (require.main === module) {
  main().catch(error => {
    logger("error", safeError(error));
    process.exit(1);
  });
}

module.exports = {
  main,
  ApplianceRuntime,
  DaemonManager,
  devicesFromRecords,
  normalizeMac,
  normalizedAccess,
  writableAccess,
  formatLogTimestamp,
  formatLogLine,
  logMessage: logger,
  normalizeLogLevel,
  shouldLog,
};
