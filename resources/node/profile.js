"use strict";

const fs = require("node:fs");
const { XMLParser } = require("fast-xml-parser");

function normalizeUid(uid) {
  if (uid === undefined || uid === null) return undefined;
  if (typeof uid === "number") return uid.toString(16).toUpperCase().padStart(4, "0");
  const text = String(uid).trim().replace(/^0x/i, "");
  if (!/^[0-9A-Fa-f]+$/.test(text)) return undefined;
  return Number.parseInt(text, 16).toString(16).toUpperCase().padStart(4, "0");
}

function uidToNumber(uid) {
  const normalized = normalizeUid(uid);
  return normalized ? Number.parseInt(normalized, 16) : undefined;
}

function arrayOf(value) {
  if (value === undefined || value === null) return [];
  return Array.isArray(value) ? value : [value];
}

function isObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function textOf(value) {
  if (typeof value === "string" || typeof value === "number") return String(value).trim();
  if (isObject(value) && value["#text"] !== undefined) return String(value["#text"]).trim();
  return "";
}

function visit(value, callback, name = "") {
  if (Array.isArray(value)) {
    for (const item of value) visit(item, callback, name);
    return;
  }
  if (!isObject(value)) return;
  callback(name, value);
  for (const [childName, child] of Object.entries(value)) {
    if (childName === "#text") continue;
    for (const item of arrayOf(child)) visit(item, callback, childName);
  }
}

function booleanAttribute(value) {
  if (value === undefined) return undefined;
  if (/^(true|1)$/i.test(String(value))) return true;
  if (/^(false|0)$/i.test(String(value))) return false;
  return undefined;
}

function deviceEnumerationComments(xml) {
  const result = {};
  const typePattern = /<enumerationType\s+[^>]*enid="([0-9A-Fa-f]+)"[^>]*>([\s\S]*?)<\/enumerationType>/g;
  for (const typeMatch of String(xml).matchAll(typePattern)) {
    const enumId = normalizeUid(typeMatch[1]);
    if (!enumId) continue;
    const values = result[enumId] || {};
    const valuePattern = /<!--\s*([^<]*?)\s*-->\s*<enumeration\s+[^>]*value="([^"]+)"[^>]*\/>/g;
    for (const valueMatch of typeMatch[2].matchAll(valuePattern)) {
      values[String(valueMatch[2])] = String(valueMatch[1]).trim();
    }
    if (Object.keys(values).length > 0) result[enumId] = values;
  }
  return result;
}

/** Parses the two XML files contained in a Home Connect appliance profile. */
function parseProfile(deviceDescriptionPath, featureMappingPath) {
  const parser = new XMLParser({
    ignoreAttributes: false,
    attributeNamePrefix: "",
    textNodeName: "#text",
    parseAttributeValue: false,
    parseTagValue: false,
    trimValues: true,
    removeNSPrefix: true,
  });
  const deviceXml = fs.readFileSync(deviceDescriptionPath, "utf8");
  const featureXml = fs.readFileSync(featureMappingPath, "utf8");
  const device = parser.parse(deviceXml);
  const features = parser.parse(featureXml);
  const featuresByUid = {};
  const enumTypeByUid = {};
  const enumValuesByType = deviceEnumerationComments(deviceXml);
  const programOptionsByUid = {};
  const descriptionsByUid = {};

  visit(features, (name, element) => {
    if (String(name).toLowerCase() === "feature" && element.refUID !== undefined) {
      const uid = normalizeUid(element.refUID);
      const featureName = textOf(element);
      if (uid && featureName) featuresByUid[uid] = featureName;
    }
    if (String(name).toLowerCase() === "enumdescription" && element.refENID !== undefined) {
      const enumId = normalizeUid(element.refENID);
      if (!enumId) return;
      const values = enumValuesByType[enumId] || {};
      visit(element, (childName, child) => {
        if (String(childName).toLowerCase() !== "enummember" || child.refValue === undefined) return;
        const label = textOf(child);
        if (label) values[String(child.refValue)] = label;
      });
      if (Object.keys(values).length > 0) enumValuesByType[enumId] = values;
    }
  });

  visit(device, (name, element) => {
    // A refUID only points to an existing definition (notably from a
    // program). Treating it as the definition itself used to overwrite the
    // real min/max/access attributes with the context of the last program.
    const uid = normalizeUid(element.uid);
    const enumType = normalizeUid(element.enumerationType);
    if (uid) {
      if (enumType) enumTypeByUid[uid] = enumType;
      const description = Object.fromEntries(
        Object.entries(element).filter(([key]) => !["uid", "refUID", "refUid", "enumerationType"].includes(key))
      );
      if (Object.keys(description).length > 0) {
        descriptionsByUid[uid] = { ...(descriptionsByUid[uid] || {}), ...description };
      }
    }

    if (String(name).toLowerCase() === "enumerationtype" && element.enid !== undefined) {
      const enumId = normalizeUid(element.enid);
      if (!enumId) return;
      const values = enumValuesByType[enumId] || {};
      for (const entry of arrayOf(element.enumeration)) {
        if (isObject(entry) && entry.value !== undefined) values[String(entry.value)] ||= String(entry.value);
      }
      if (Object.keys(values).length > 0) enumValuesByType[enumId] = values;
    }

    if (String(name).toLowerCase() === "program" && uid) {
      const options = [];
      visit(element, (childName, child) => {
        const lower = String(childName).toLowerCase();
        if (!["option", "optionref", "programoption"].includes(lower)) return;
        const optionUid = normalizeUid(child.refUID ?? child.refUid ?? child.uid);
        if (!optionUid || options.some(option => option.refUID === optionUid)) return;
        options.push({
          refUID: optionUid,
          access: child.access !== undefined ? String(child.access) : undefined,
          available: booleanAttribute(child.available),
          default: child.default,
          min: child.min,
          max: child.max,
          step: child.step ?? child.stepSize ?? child.increment,
        });
      });
      if (options.length > 0) programOptionsByUid[uid] = options;
    }
  });

  const featureUidByName = Object.fromEntries(Object.entries(featuresByUid).map(([uid, feature]) => [feature, uid]));
  const knownProgramOptionUids = new Set(
    Object.values(programOptionsByUid).flat().map(option => normalizeUid(option.refUID)).filter(Boolean)
  );
  const writableProgramOptionUids = new Set(
    Object.values(programOptionsByUid).flat()
      .filter(option => ["readwrite", "write"].includes(String(option.access || "").replace(/[^a-z]/gi, "").toLowerCase()))
      .map(option => normalizeUid(option.refUID)).filter(Boolean)
  );
  return {
    featuresByUid,
    featureUidByName,
    enumTypeByUid,
    enumValuesByType,
    programOptionsByUid,
    knownProgramOptionUids,
    writableProgramOptionUids,
    descriptionsByUid,
  };
}

function markerPart(featureName) {
  const name = String(featureName || "");
  for (const marker of [".Status.", ".Option.", ".Setting.", ".Event.", ".Command.", ".Program.", ".Root."]) {
    const position = name.indexOf(marker);
    if (position >= 0) return name.slice(position + marker.length);
  }
  return name.split(".").pop() || name;
}

function categoryFor(featureName) {
  const name = String(featureName || "");
  const short = markerPart(name);
  if (["ProgramPhase", "ProcessPhase"].includes(short)) return "phases";
  if (name.includes(".Root.")) return "program";
  if (name.includes(".Status.")) return "status";
  if (name.includes(".Option.")) return "options";
  if (name.includes(".Setting.")) return "settings";
  if (name.includes(".Command.")) return "commands";
  if (name.includes(".Event.")) return "events";
  if (name.includes(".Program.")) return "programs";
  return "information";
}

function humanize(value) {
  return String(value || "")
    .split(".").pop()
    .replace(/([a-zà-ÿ])([A-Z])/g, "$1 $2")
    .replace(/([A-Za-z])([0-9])/g, "$1 $2")
    .replace(/[_-]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function metadataFor(featureName, uid, profile, description = {}) {
  const metadata = {};
  for (const key of ["min", "minimum", "minValue"]) {
    if (Number.isFinite(Number(description[key]))) { metadata.min = Number(description[key]); break; }
  }
  for (const key of ["max", "maximum", "maxValue"]) {
    if (Number.isFinite(Number(description[key]))) { metadata.max = Number(description[key]); break; }
  }
  for (const key of ["step", "stepSize", "increment"]) {
    if (Number.isFinite(Number(description[key]))) { metadata.step = Number(description[key]); break; }
  }
  metadata.unit = description.unit || description.unitOfMeasure || undefined;
  const declaredType = String(description.dataType || description.type || "").trim().toLowerCase();
  // Le profil Home Connect identifie les booléens par la paire standard
  // contenu/type 01/00. La valeur courante ne suffit pas toujours : un
  // réglage inscriptible peut être annoncé avant sa première valeur.
  if (String(description.refCID || "").toUpperCase() === "01"
      && String(description.refDID || "").toUpperCase() === "00") {
    metadata.type = "Boolean";
  }
  if (["bool", "boolean"].includes(declaredType)) metadata.type = "Boolean";
  if (description.default !== undefined) metadata.default = description.default;
  else if (description.initValue !== undefined) metadata.default = description.initValue;
  if (!metadata.unit && /(?:Duration|ElapsedProgramTime|RemainingProgramTime|EstimatedTotalProgramTime|StartInRelative|FinishInRelative|StopWatchTime|SwitchOffTimer)$/i.test(featureName)) metadata.unit = "s";
  if (!metadata.unit && /(?:IntervalTime|ShutOffTime|AlarmClock|Countdown)$/i.test(featureName)) metadata.unit = "s";
  if (/Cooking\.Hob\.Setting\.AutomaticTimer$/i.test(featureName)) metadata.unit = "min";
  if (/ProgramProgress$/i.test(featureName)) Object.assign(metadata, { unit: "%", min: 0, max: 100 });
  if (/(?:Progress|Forecast|FillLevel|Saturation)$/i.test(featureName)) metadata.unit ||= "%";
  if (!metadata.unit && /Temperature/i.test(featureName)) metadata.unit = "°C";
  if (!metadata.unit && /(?:CurrentPower|InstantaneousPower|PowerConsumption|PowerRating)$/i.test(featureName)) metadata.unit = "W";
  if (!metadata.unit && /PowerRating$/i.test(featureName)) metadata.unit = "W";
  if (!metadata.unit && /Energy/i.test(featureName)) metadata.unit = "kWh";
  if (!metadata.unit && /FillQuantity$/i.test(featureName)) metadata.unit = "ml";
  if (!metadata.unit && /SpinSpeed$/i.test(featureName)) metadata.unit = "tr/min";
  if (!metadata.unit && /WiFiSignalStrength$/i.test(featureName)) metadata.unit = "dBm";
  if (!metadata.unit && /Cooking\.Hob\.Status(?:\.Zone\.\d+)?\.Length[XY]$/i.test(featureName)) metadata.unit = "cm";
  if (/(?:WaterForecast|EnergyForecast)$/i.test(featureName)) Object.assign(metadata, { unit: "%", min: 0, max: 100 });
  const enumType = profile.enumTypeByUid[uid];
  if (enumType && profile.enumValuesByType[enumType]) {
    metadata.states = Object.fromEntries(
      Object.entries(profile.enumValuesByType[enumType]).map(([raw, label]) => [raw, humanize(label)])
    );
    delete metadata.unit;
  }
  return metadata;
}

function protocolTypeFor(rawValue, metadata, description = {}) {
  const declared = String(description.dataType || description.type || "").trim().toLowerCase();
  if (["bool", "boolean"].includes(declared) || String(metadata.type || "").toLowerCase() === "boolean") return "boolean";
  if (["byte", "short", "int", "integer", "long", "uint", "uint8", "uint16", "uint32"].includes(declared)) return "integer";
  if (["float", "double", "decimal", "number"].includes(declared)) return "number";
  if (["string", "text", "char"].includes(declared)) return "string";
  if (typeof rawValue === "boolean") return "boolean";
  if (typeof rawValue === "number") return Number.isInteger(rawValue) ? "integer" : "number";
  if (metadata.min !== undefined || metadata.max !== undefined) return "number";
  return "string";
}

function valueFor(rawValue, uid, profile) {
  const enumType = profile.enumTypeByUid[uid];
  const label = enumType ? profile.enumValuesByType[enumType]?.[String(rawValue)] : undefined;
  if (label !== undefined) return humanize(label);
  const currentFeature = profile.featuresByUid[uid] || "";
  // Only program pointers contain another feature UID. Dereferencing every
  // numeric value could turn a duration or a power into an unrelated command
  // whenever their value happened to match a known UID.
  if (typeof rawValue === "number" && /(?:ActiveProgram|SelectedProgram)$/i.test(currentFeature)) {
    const referencedFeature = profile.featuresByUid[normalizeUid(rawValue)];
    if (referencedFeature) return referencedFeature;
  }
  if (["string", "number", "boolean"].includes(typeof rawValue) || rawValue === null) return rawValue;
  return JSON.stringify(rawValue);
}

function isDangerousFeature(featureName) {
  const normalized = String(featureName || "").toLowerCase();
  return [
    "factoryreset", "networkreset", "resetnetwork", "deactivatewifi", "disablewifi",
    "softwareupdate", "softwaredownload", "firmwareupdate", "firmwaredownload",
    "unregister", "deleteappliance", "deleteuser", "formatstorage",
  ].some(marker => normalized.includes(marker));
}

/**
 * Returns whether a writable XML capability is safe to expose automatically.
 * Settings, options and program roots are constrained values described by the
 * profile. Impulse commands are open-ended, so only common reversible actions
 * are enabled without an explicit administrator opt-in.
 */
function isKnownSafeWritableFeature(featureName) {
  const feature = String(featureName || "");
  if (!feature || isDangerousFeature(feature)) return false;
  if (/\.(?:Setting|Option)\./.test(feature)) return true;
  if (/\.Root\.(?:SelectedProgram|ActiveProgram)$/.test(feature)) return true;
  if (!feature.includes(".Command.")) return false;
  const action = markerPart(feature).replace(/[^A-Za-z0-9]/g, "");
  return /^(?:Start|Stop|Pause|Resume|Cancel|Abort|Open|Close|On|Off|Activate|Deactivate|Enable|Disable|Confirm|Acknowledge|ResetAlarm|Refresh)/i.test(action);
}

function entityFor(uidValue, rawValue, description, profile) {
  const uid = normalizeUid(uidValue);
  if (!uid) return null;
  const feature = profile.featuresByUid[uid] || `HomeConnect.Unknown.uid_${uid}`;
  const metadata = metadataFor(feature, uid, profile, description);
  metadata.protocolType = protocolTypeFor(rawValue, metadata, description);
  let subtype = "string";
  if (Object.keys(metadata.states || {}).length > 0) subtype = "string";
  else if (typeof rawValue === "boolean") subtype = "binary";
  else if (typeof rawValue === "number") subtype = "numeric";
  return {
    uid,
    uidNumber: uidToNumber(uid),
    feature,
    name: humanize(markerPart(feature)) || `UID ${uid}`,
    category: categoryFor(feature),
    rawValue,
    value: valueFor(rawValue, uid, profile),
    subtype,
    metadata,
    dangerous: isDangerousFeature(feature),
    safeWritable: isKnownSafeWritableFeature(feature),
  };
}

function programsFor(profile) {
  return Object.entries(profile.featuresByUid)
    .filter(([, feature]) => feature.includes(".Program.") && !feature.includes(".Root."))
    .map(([uid, feature]) => ({ uid: uidToNumber(uid), uidHex: uid, feature, name: humanize(feature) }))
    .filter(program => Number.isFinite(program.uid))
    .sort((left, right) => left.name.localeCompare(right.name));
}

module.exports = {
  parseProfile,
  normalizeUid,
  uidToNumber,
  markerPart,
  categoryFor,
  humanize,
  metadataFor,
  protocolTypeFor,
  valueFor,
  entityFor,
  programsFor,
  isDangerousFeature,
  isKnownSafeWritableFeature,
};
