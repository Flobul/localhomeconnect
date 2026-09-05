"use strict";

/*
 * Transport derived from the protocol descriptions in osresearch/hcpy and
 * dosordie/ioBroker.homeconnect-local, both distributed under the MIT license.
 */

const crypto = require("node:crypto");
const { EventEmitter } = require("node:events");
const WebSocket = require("ws");

const ENCRYPT_DIRECTION = Buffer.from([0x45]);
const DECRYPT_DIRECTION = Buffer.from([0x43]);

function base64UrlDecode(value) {
  const normalized = String(value || "").replace(/-/g, "+").replace(/_/g, "/");
  return Buffer.from(normalized.padEnd(Math.ceil(normalized.length / 4) * 4, "="), "base64");
}

function formatHost(host) {
  const value = String(host || "").trim();
  return value.includes(":") && !value.startsWith("[") ? `[${value}]` : value;
}

function rawDataToBuffer(data) {
  if (Buffer.isBuffer(data)) return data;
  if (Array.isArray(data)) return Buffer.concat(data);
  return Buffer.from(data);
}

/**
 * Base WebSocket transport with a small receive queue to avoid losing the
 * initial appliance frame between the open event and the protocol listener.
 */
class QueuedTransport extends EventEmitter {
  constructor() {
    super();
    this.ws = null;
    this.queue = [];
    this.waiters = [];
    this.lastError = null;
    // `error` is a special EventEmitter event: without a listener Node stops
    // the process. Keep a local listener from construction time so a malformed
    // first appliance frame can only close this transport, never the daemon.
    this.on("error", () => undefined);
  }

  get closed() {
    return !this.ws || this.ws.readyState === WebSocket.CLOSED || this.ws.readyState === WebSocket.CLOSING;
  }

  pushMessage(payload) {
    const waiter = this.waiters.shift();
    if (waiter) {
      clearTimeout(waiter.timer);
      waiter.resolve(payload);
      return;
    }
    // The queue only bridges the short interval between the socket opening and
    // HomeConnectProtocol installing its permanent listener. Once a listener
    // exists, retaining a second copy of every frame would grow memory for the
    // whole lifetime of the daemon.
    if (this.listenerCount("message") === 0) {
      this.queue.push(payload);
      if (this.queue.length > 16) this.queue.shift();
      return;
    }
    this.emit("message", payload);
  }

  nextMessage(timeoutMs = 15000) {
    if (this.lastError) return Promise.reject(this.lastError);
    if (this.queue.length > 0) return Promise.resolve(this.queue.shift());
    return new Promise((resolve, reject) => {
      const waiter = { resolve, reject, timer: null };
      waiter.timer = setTimeout(() => {
        const index = this.waiters.indexOf(waiter);
        if (index >= 0) this.waiters.splice(index, 1);
        reject(new Error("Délai d’attente du message Home Connect dépassé"));
      }, timeoutMs);
      this.waiters.push(waiter);
    });
  }

  rejectWaiters(error) {
    for (const waiter of this.waiters.splice(0)) {
      clearTimeout(waiter.timer);
      waiter.reject(error);
    }
  }

  reportError(error, terminate = false) {
    const normalized = error instanceof Error ? error : new Error(String(error));
    this.lastError = normalized;
    this.rejectWaiters(normalized);
    this.emit("error", normalized);
    if (terminate && this.ws && this.ws.readyState !== WebSocket.CLOSED) {
      this.ws.terminate();
    }
  }

  async close() {
    const current = this.ws;
    this.rejectWaiters(new Error("Transport Home Connect fermé"));
    this.queue.length = 0;
    if (!current || current.readyState === WebSocket.CLOSED) return;
    await new Promise(resolve => {
      let done = false;
      const finish = () => {
        if (done) return;
        done = true;
        clearTimeout(timer);
        resolve();
      };
      const timer = setTimeout(() => {
        current.terminate();
        finish();
      }, 2000);
      current.once("close", finish);
      current.close();
    });
  }

  connectSocket(url, options, decode, timeoutMs) {
    return new Promise((resolve, reject) => {
      this.queue.length = 0;
      this.lastError = null;
      const ws = new WebSocket(url, { perMessageDeflate: false, ...options });
      this.ws = ws;
      let opened = false;
      const timer = setTimeout(() => {
        ws.terminate();
        reject(new Error(`Connexion à ${url} impossible dans le délai imparti`));
      }, timeoutMs);
      ws.on("message", data => {
        try {
          this.pushMessage(decode(data));
        } catch (error) {
          this.reportError(error, true);
        }
      });
      ws.on("open", () => {
        opened = true;
        clearTimeout(timer);
        resolve();
      });
      ws.on("error", error => {
        if (!opened) {
          clearTimeout(timer);
          reject(error);
        } else {
          this.reportError(error);
        }
      });
      ws.on("close", (code, reason) => {
        clearTimeout(timer);
        this.rejectWaiters(new Error(`Socket fermé : ${code} ${reason.toString("utf8")}`));
        this.emit("close", code, reason.toString("utf8"));
      });
    });
  }
}

/** Implements the Home Connect AES-CBC/HMAC WebSocket transport. */
class AesTransport extends QueuedTransport {
  constructor(host, psk64, iv64) {
    super();
    this.url = `ws://${formatHost(host)}:80/homeconnect`;
    const psk = base64UrlDecode(psk64);
    this.iv = base64UrlDecode(iv64);
    this.encKey = crypto.createHmac("sha256", psk).update("ENC").digest();
    this.macKey = crypto.createHmac("sha256", psk).update("MAC").digest();
    this.cipher = null;
    this.decipher = null;
    this.lastRxHmac = Buffer.alloc(16);
    this.lastTxHmac = Buffer.alloc(16);
  }

  async connect(timeoutMs = 15000) {
    this.lastRxHmac = Buffer.alloc(16);
    this.lastTxHmac = Buffer.alloc(16);
    this.cipher = crypto.createCipheriv("aes-256-cbc", this.encKey, this.iv);
    this.decipher = crypto.createDecipheriv("aes-256-cbc", this.encKey, this.iv);
    this.cipher.setAutoPadding(false);
    this.decipher.setAutoPadding(false);
    await this.connectSocket(this.url, {}, data => this.decrypt(rawDataToBuffer(data)), timeoutMs);
  }

  async send(clearText) {
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN || !this.cipher) {
      throw new Error("WebSocket AES non connecté");
    }
    const clear = Buffer.from(String(clearText), "utf8");
    let padLength = 16 - (clear.length % 16);
    if (padLength === 1) padLength += 16;
    const padded = Buffer.concat([
      clear,
      Buffer.from([0]),
      padLength > 2 ? crypto.randomBytes(padLength - 2) : Buffer.alloc(0),
      Buffer.from([padLength]),
    ]);
    const encrypted = this.cipher.update(padded);
    this.lastTxHmac = crypto.createHmac("sha256", this.macKey)
      .update(Buffer.concat([this.iv, ENCRYPT_DIRECTION, this.lastTxHmac, encrypted]))
      .digest()
      .subarray(0, 16);
    await new Promise((resolve, reject) => {
      this.ws.send(Buffer.concat([encrypted, this.lastTxHmac]), error => error ? reject(error) : resolve());
    });
  }

  decrypt(payload) {
    if (!this.decipher || payload.length < 32 || payload.length % 16 !== 0) {
      throw new Error("Trame AES Home Connect invalide");
    }
    const encrypted = payload.subarray(0, -16);
    const receivedHmac = payload.subarray(-16);
    const expectedHmac = crypto.createHmac("sha256", this.macKey)
      .update(Buffer.concat([this.iv, DECRYPT_DIRECTION, this.lastRxHmac, encrypted]))
      .digest()
      .subarray(0, 16);
    if (!crypto.timingSafeEqual(receivedHmac, expectedHmac)) {
      throw new Error("Authentification HMAC Home Connect invalide");
    }
    this.lastRxHmac = Buffer.from(receivedHmac);
    const clear = this.decipher.update(encrypted);
    const padLength = clear[clear.length - 1];
    if (padLength <= 0 || padLength > clear.length) {
      throw new Error("Remplissage AES Home Connect invalide");
    }
    return clear.subarray(0, -padLength).toString("utf8");
  }
}

/** Implements the TLS 1.2 PSK WebSocket transport used by newer appliances. */
class TlsPskTransport extends QueuedTransport {
  constructor(host, psk64, identity) {
    super();
    this.url = `wss://${formatHost(host)}:443/homeconnect`;
    this.psk = base64UrlDecode(psk64);
    this.identity = String(identity || "jeedom-localhomeconnect");
  }

  async connect(timeoutMs = 15000) {
    await this.connectSocket(
      this.url,
      {
        rejectUnauthorized: false,
        minVersion: "TLSv1.2",
        maxVersion: "TLSv1.2",
        ciphers: "PSK:@SECLEVEL=0",
        pskCallback: () => ({ identity: this.identity, psk: this.psk }),
      },
      data => rawDataToBuffer(data).toString("utf8"),
      timeoutMs
    );
  }

  async send(clearText) {
    if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
      throw new Error("WebSocket TLS-PSK non connecté");
    }
    await new Promise((resolve, reject) => {
      this.ws.send(String(clearText), error => error ? reject(error) : resolve());
    });
  }
}

module.exports = { AesTransport, TlsPskTransport, QueuedTransport, base64UrlDecode };
