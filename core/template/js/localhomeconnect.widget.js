"use strict";

(function () {
  function initialize(widget) {
    if (widget.getAttribute("data-localhomeconnect-bound") === "1") return;
    widget.setAttribute("data-localhomeconnect-bound", "1");
    const health = widget.querySelector(".health");
    const connectedId = health?.getAttribute("data-cmd_id") || "";

    function setConnectionState(online) {
      widget.setAttribute("data-online", online ? "true" : "false");
      widget.classList.toggle("localhomeconnect-offline", !online);
      const icon = health?.querySelector("i");
      if (health) {
        const titleAttribute = online ? "data-title-online" : "data-title-offline";
        health.setAttribute("data-title", health.getAttribute(titleAttribute) || "");
      }
      if (icon) icon.className = online ? "fas fa-link" : "fas fa-unlink";
    }

    widget.addEventListener("click", function (event) {
      const tab = event.target.closest(".localhomeconnect-tab");
      if (tab) {
        event.preventDefault();
        event.stopPropagation();
        const page = tab.getAttribute("data-page");
        widget.querySelectorAll(".localhomeconnect-tab").forEach(item => item.classList.toggle("active", item === tab));
        widget.querySelectorAll(".localhomeconnect-page").forEach(item => item.classList.toggle("active", item.getAttribute("data-page") === page));
        return;
      }
      const refresh = event.target.closest(".localhomeconnect-refresh");
      if (refresh) {
        event.preventDefault();
        jeedom.cmd.execute({ id: refresh.getAttribute("data-cmd_id") });
        return;
      }
      if (widget.getAttribute("data-online") !== "true" && event.target.closest(".localhomeconnect-command-action")) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    }, true);

    widget.addEventListener("change", function (event) {
      const toggle = event.target.closest(".localhomeconnect-toggle");
      if (!toggle) return;
      const previous = !toggle.checked;
      const commandId = toggle.getAttribute(toggle.checked ? "data-on-cmd_id" : "data-off-cmd_id");
      toggle.disabled = true;
      jeedom.cmd.execute({
        id: commandId,
        success: function () { toggle.disabled = false; },
        error: function (error) {
          toggle.checked = previous;
          toggle.disabled = false;
          jeedomUtils.showAlert({ message: error.message || error, level: "danger" });
        },
      });
    });

    widget.querySelectorAll(".localhomeconnect-toggle").forEach(function (toggle) {
      const stateId = toggle.getAttribute("data-state-cmd_id");
      if (!stateId) return;
      jeedom.cmd.addUpdateFunction(stateId, function (_options) {
        const value = typeof _options.value !== "undefined" ? _options.value : _options.display_value;
        toggle.checked = ["1", "true", "on", "enabled"].includes(String(value).toLowerCase());
      });
    });

    widget.querySelectorAll(".localhomeconnect-progress").forEach(function (progress) {
      if (progress.closest(".localhomeconnect-zone-card")) return;
      const frame = progress.closest("[data-cmd_id]");
      const commandId = progress.getAttribute("data-progress-cmd_id") || frame?.getAttribute("data-cmd_id");
      if (!commandId) return;
      jeedom.cmd.addUpdateFunction(commandId, function (_options) {
        let value = parseFloat(String(typeof _options.value !== "undefined" ? _options.value : _options.display_value).replace(",", "."));
        value = Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
        progress.setAttribute("aria-valuenow", value);
        const fill = progress.querySelector("span");
        if (fill) fill.style.width = value + "%";
      });
    });

    function displayLiveValue(element, rawValue) {
      const value = rawValue == null || String(rawValue).trim() === "" ? "—" : String(rawValue).trim();
      const unit = element.getAttribute("data-unit") || "";
      element.setAttribute("data-current-value", value === "—" ? "" : value);
      element.textContent = unit && !value.toLowerCase().includes(unit.toLowerCase()) ? value + " " + unit : value;
    }

    function synchronizeZone(zone) {
      const state = (zone.querySelector('[data-zone-role="state"]')?.getAttribute("data-current-value") || "").toLowerCase();
      const power = (zone.querySelector('[data-zone-role="power"]')?.getAttribute("data-current-value") || "").toLowerCase();
      const combined = state + " " + power;
      const residual = /résid|resid|chaleur|heat/.test(combined);
      const inactiveState = /inactif|inactive|éteint|eteint|arrêt|arret|\boff\b|non sélectionnable|not selectable/.test(state);
      const inactivePower = power === "" || /^(?:0|—|-|éteint|eteint|arrêt|arret|off)$/.test(power.trim());
      const explicitlyActive = /actif|active|run|cours|maintien|boost|intensif/.test(combined);
      const active = !residual && (explicitlyActive || (!inactiveState && !inactivePower));
      zone.classList.toggle("is-active", active);
      zone.classList.toggle("is-idle", !active && !residual);
      zone.classList.toggle("has-residual-heat", residual);
      zone.querySelectorAll(".fa-hourglass-half").forEach(function (icon) {
        icon.classList.toggle("fa-spin", active);
      });
    }

    widget.querySelectorAll("[data-lhc-live-cmd]").forEach(function (element) {
      const commandId = element.getAttribute("data-lhc-live-cmd");
      if (!commandId) return;
      jeedom.cmd.addUpdateFunction(commandId, function (_options) {
        const value = typeof _options.display_value !== "undefined" ? _options.display_value : _options.value;
        displayLiveValue(element, value);
        const zone = element.closest(".localhomeconnect-zone-card");
        if (zone) {
          if (element.getAttribute("data-zone-role") === "progress") {
            let percentage = parseFloat(String(value).replace(",", "."));
            percentage = Number.isFinite(percentage) ? Math.max(0, Math.min(100, percentage)) : 0;
            const progress = zone.querySelector(".localhomeconnect-progress");
            if (progress) {
              progress.setAttribute("aria-valuenow", percentage);
              const fill = progress.querySelector("span");
              if (fill) fill.style.width = percentage + "%";
            }
          }
          synchronizeZone(zone);
        }
      });
    });
    widget.querySelectorAll(".localhomeconnect-zone-card").forEach(synchronizeZone);

    const operation = widget.querySelector('[data-feature$="OperationState"]');
    function syncOperatingState(rawValue) {
      const value = String(rawValue || "").toLowerCase();
      const active = /run|running|en cours|washing|lavage|rinsing|rinçage|spinning|essorage|drying|séchage|heating|cuisson|cleaning|nettoyage/.test(value);
      widget.querySelectorAll('.localhomeconnect-command-icon .fa-hourglass-half').forEach(function (icon) {
        icon.classList.toggle("fa-spin", active);
        icon.closest(".localhomeconnect-widget-command")?.classList.toggle("localhomeconnect-command-active", active);
      });
    }
    if (operation) {
      syncOperatingState(operation.getAttribute("data-current-value"));
      jeedom.cmd.addUpdateFunction(operation.getAttribute("data-cmd_id"), function (_options) {
        const value = typeof _options.value !== "undefined" ? _options.value : _options.display_value;
        operation.setAttribute("data-current-value", value == null ? "" : value);
        syncOperatingState(value);
      });
    }

    if (connectedId) {
      jeedom.cmd.addUpdateFunction(connectedId, function (_options) {
        const online = Number(typeof _options.value !== "undefined" ? _options.value : _options.display_value) === 1;
        setConnectionState(online);
      });
    }
    setConnectionState(widget.getAttribute("data-online") === "true");
  }

  document.querySelectorAll(".localhomeconnect-widget").forEach(initialize);
})();
