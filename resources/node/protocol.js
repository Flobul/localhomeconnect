"use strict";

const crypto = require("node:crypto");
const { EventEmitter } = require("node:events");

const RESPONSE_CODES = {
  400: "requête invalide", 403: "accès refusé", 404: "ressource absente",
  429: "trop de requêtes", 500: "erreur interne de l’appareil", 512: "UID inconnu",
  515: "appareil occupé", 518: "accès interdit", 524: "fonction indisponible",
  530: "exécution impossible", 531: "valeur hors limites", 532: "valeur invalide",
  534: "combinaison d’options incohérente", 535: "commande refusée", 536: "format invalide",
  537: "contrôle à distance inactif", 538: "démarrage distant inactif",
  539: "verrouillé par une commande locale", 540: "état de l’appareil incompatible",
  541: "état du programme incompatible", 544: "appareil absent du Wi-Fi local",
};

function parseMessage(payload) {
  try {
    const value = JSON.parse(String(payload));
    if (!value || !value.resource || !value.action) throw new Error("message incomplet");
    return value;
  } catch (error) {
    const text = String(payload).trim();
    if (text.includes('"resource":"/ro/allMandatoryValues"') && /\}\}\s*$/.test(text)) {
      const lastBrace = text.lastIndexOf("}");
      try {
        return JSON.parse(`${text.slice(0, lastBrace)}]${text.slice(lastBrace)}`);
      } catch (_) {
        // Continue with the original parsing error.
      }
    }
    throw new Error(`JSON Home Connect invalide : ${error.message}`);
  }
}

function serviceFor(resource) {
  return String(resource || "").replace(/^\//, "").slice(0, 2);
}

/** Maintains a Home Connect application session over an encrypted transport. */
class HomeConnectProtocol extends EventEmitter {
  constructor(transport, options = {}) {
    super();
    this.transport = transport;
    this.appName = String(options.appName || "Jeedom LocalHomeConnect");
    this.appId = String(options.appId || "jeedom-localhomeconnect");
    this.logger = options.logger || { debug() {}, info() {}, warn() {}, error() {} };
    this.sid = undefined;
    this.nextId = 1;
    this.services = {};
    this.pending = new Map();
    this.connected = false;
    this.closing = false;
    // Protect direct/probe users of this class from EventEmitter's fatal
    // unhandled `error` semantics. ApplianceRuntime may add a richer listener.
    this.on("error", () => undefined);
  }

  async connect(timeoutMs = 45000) {
    const deadline = Date.now() + Math.max(5000, Number(timeoutMs) || 45000);
    const remaining = maximum => {
      const available = deadline - Date.now();
      if (available <= 0) throw new Error("Délai global de connexion Home Connect dépassé");
      return Math.max(500, Math.min(maximum, available));
    };
    // Errors and closures must be observed before opening the socket: the
    // first encrypted frame may itself be malformed.
    this.transport.on("error", error => this.emit("error", error));
    this.transport.on("close", (code, reason) => {
      this.connected = false;
      this.rejectPending(new Error(`Socket fermé : ${code} ${reason}`));
      if (!this.closing) this.emit("close", code, reason);
    });
    await this.transport.connect(remaining(15000));
    const initial = parseMessage(await this.transport.nextMessage(remaining(15000)));
    if (initial.resource !== "/ei/initialValues") {
      throw new Error(`Première ressource inattendue : ${initial.resource}`);
    }
    this.sid = initial.sID;
    const advertisedId = Array.isArray(initial.data) ? Number(initial.data[0]?.edMsgID) : NaN;
    this.nextId = Number.isFinite(advertisedId) ? advertisedId : Number(initial.msgID || 0) + 1;
    this.transport.on("message", payload => this.handlePayload(payload));

    await this.sendRaw({
      sID: initial.sID,
      msgID: initial.msgID,
      resource: initial.resource,
      version: initial.version,
      action: "RESPONSE",
      data: [{
        deviceType: initial.version === 1 ? 2 : "Application",
        deviceName: this.appName,
        deviceID: this.appId,
      }],
    });

    const services = await this.sendSync({ resource: "/ci/services", version: 1, action: "GET" }, remaining(15000));
    for (const item of Array.isArray(services.data) ? services.data : []) {
      if (item && item.service) this.services[String(item.service)] = Number(item.version || 1);
    }
    this.emit("message", services);

    if ((this.services.ci || 1) < 3) {
      await this.sendSync({
        resource: "/ci/authentication",
        action: "GET",
        data: [{ nonce: crypto.randomBytes(32).toString("base64url") }],
      }, remaining(15000));
    }
    await this.optionalGet("/ci/info", remaining(12000));
    if (this.services.iz !== undefined) await this.optionalGet("/iz/info", remaining(12000));
    if ((this.services.ei || 1) === 2) {
      await this.send({ resource: "/ei/deviceReady", action: "NOTIFY" });
    }
    if (this.services.ni !== undefined) await this.optionalGet("/ni/info", remaining(12000));
    this.connected = true;
  }

  async readInitialValues(timeoutMs = 24000) {
    const deadline = Date.now() + Math.max(5000, Number(timeoutMs) || 24000);
    const result = {};
    for (const resource of [
      "/ro/allDescriptionChanges",
      "/ro/allMandatoryValues",
      "/ro/availablePrograms",
      "/ro/selectedProgram",
      "/ro/activeProgram",
    ]) {
      const remaining = deadline - Date.now();
      if (remaining <= 0) {
        result[resource] = false;
        continue;
      }
      result[resource] = await this.optionalGet(resource, Math.max(500, Math.min(8000, remaining))) !== null;
    }
    return result;
  }

  async optionalGet(resource, timeoutMs = 12000) {
    try {
      const response = await this.sendSync({ resource, action: "GET" }, timeoutMs);
      this.emit("message", response);
      return response;
    } catch (error) {
      if (![404, 524].includes(Number(error?.homeConnectCode))) throw error;
      this.logger.debug(`${resource} indisponible : ${error.message}`);
      return null;
    }
  }

  async heartbeat() {
    const resources = this.services.ni !== undefined ? ["/ni/info", "/ci/info"] : ["/ci/info"];
    let lastError = null;
    for (const resource of resources) {
      try {
        return await this.sendSync({
          resource,
          version: this.services[serviceFor(resource)] || 1,
          action: "GET",
        }, 10000);
      } catch (error) {
        lastError = error;
        if (![404, 524].includes(Number(error?.homeConnectCode))) throw error;
      }
    }
    throw lastError || new Error("Aucun service Home Connect disponible pour la surveillance");
  }

  async writeValue(uid, value) {
    return this.sendSync({ resource: "/ro/values", action: "POST", data: [{ uid: Number(uid), value }] }, 20000);
  }

  async readValues(uids) {
    const requested = [...new Set((Array.isArray(uids) ? uids : [uids]).map(Number).filter(Number.isFinite))];
    if (requested.length === 0) return null;
    const response = await this.sendSync({
      resource: "/ro/values",
      action: "GET",
      data: requested.map(uid => ({ uid })),
    }, 10000);
    this.emit("message", response);
    return response;
  }

  async selectProgram(program, options = []) {
    return this.sendSync({
      resource: "/ro/selectedProgram",
      action: "POST",
      data: [{ program: Number(program), options }],
    }, 20000);
  }

  async startProgram(program, options = []) {
    return this.sendSync({
      resource: "/ro/activeProgram",
      action: "POST",
      data: [{ program: Number(program), options }],
    }, 20000);
  }

  async send(message) {
    return this.sendRaw(this.prepare(message));
  }

  async sendSync(message, timeoutMs = 15000) {
    const prepared = this.prepare(message);
    const key = Number(prepared.msgID);
    const promise = new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(key);
        reject(new Error(`Réponse Home Connect absente pour ${prepared.resource}`));
      }, timeoutMs);
      this.pending.set(key, { resolve, reject, timer, resource: prepared.resource });
    });
    try {
      await this.sendRaw(prepared);
      const response = await promise;
      if (response.code !== undefined && Number(response.code) !== 0) {
        const code = Number(response.code);
        const detail = RESPONSE_CODES[code] ? ` (${RESPONSE_CODES[code]})` : "";
        const error = new Error(`Code Home Connect ${code}${detail} pour ${response.resource}`);
        error.homeConnectCode = code;
        error.homeConnectResource = response.resource;
        throw error;
      }
      return response;
    } catch (error) {
      const pending = this.pending.get(key);
      if (pending) clearTimeout(pending.timer);
      this.pending.delete(key);
      throw error;
    }
  }

  prepare(message) {
    return {
      ...message,
      sID: message.sID ?? this.sid,
      msgID: message.msgID ?? this.nextId++,
      version: message.version ?? this.services[serviceFor(message.resource)] ?? 1,
    };
  }

  async sendRaw(message) {
    const payload = {
      sID: message.sID,
      msgID: message.msgID,
      resource: message.resource,
      version: message.version,
      action: message.action,
    };
    if (message.data !== undefined && message.data !== null) {
      payload.data = Array.isArray(message.data) ? message.data : [message.data];
    }
    if (message.code !== undefined) payload.code = message.code;
    this.logger.debug(`SEND ${payload.action} ${payload.resource} #${payload.msgID}`);
    await this.transport.send(JSON.stringify(payload));
  }

  handlePayload(payload) {
    let message;
    try {
      message = parseMessage(payload);
    } catch (error) {
      this.logger.warn(error.message);
      return;
    }
    this.logger.debug(`RECV ${message.action} ${message.resource} #${message.msgID}`);
    if (message.action === "RESPONSE" && this.pending.has(Number(message.msgID))) {
      const pending = this.pending.get(Number(message.msgID));
      clearTimeout(pending.timer);
      this.pending.delete(Number(message.msgID));
      pending.resolve(message);
      return;
    }
    this.emit("message", message);
  }

  rejectPending(error) {
    for (const pending of this.pending.values()) {
      clearTimeout(pending.timer);
      pending.reject(error);
    }
    this.pending.clear();
  }

  async close() {
    this.closing = true;
    this.connected = false;
    this.rejectPending(new Error("Session Home Connect fermée"));
    await this.transport.close();
  }
}

module.exports = { HomeConnectProtocol, parseMessage };
