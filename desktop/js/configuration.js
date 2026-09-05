"use strict";

(function () {
  const endpoint = "plugins/localhomeconnect/core/ajax/localhomeconnect.ajax.php";

  function showError(error) {
    jeedomUtils.showAlert({ message: error && error.message ? error.message : String(error), level: "danger" });
  }

  function request(action, data, success, failure) {
    domUtils.ajax({
      type: "POST",
      url: endpoint,
      data: Object.assign({ action: action }, data || {}),
      dataType: "json",
      error: failure || handleAjaxError,
      success: function (response) {
        if (response.state !== "ok") return showError(response.result);
        if (success) success(response.result);
      },
    });
  }

  function renderProfiles(profiles) {
    const body = document.querySelector("#table_localhomeconnect_profiles tbody");
    const count = document.getElementById("localhomeconnect-profile-count");
    if (!body) return;
    body.innerHTML = "";
    if (!Array.isArray(profiles) || profiles.length === 0) {
      if (count) count.textContent = "0";
      const row = body.insertRow();
      const cell = row.insertCell();
      cell.colSpan = 6;
      cell.className = "text-center text-muted";
      cell.textContent = "{{Aucun profil importé}}";
      return;
    }
    if (count) count.textContent = String(profiles.length);
    profiles.forEach(function (profile) {
      const row = body.insertRow();
      [profile.name || profile.haId, profile.type, profile.vib, profile.connectionType, profile.importedAt].forEach(function (value) {
        row.insertCell().textContent = value || "—";
      });
      const actions = row.insertCell();
      actions.className = "text-center";
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "btn btn-danger btn-xs";
      remove.title = "{{Supprimer ce profil et ses clés locales}}";
      remove.innerHTML = '<i class="fas fa-trash"></i>';
      remove.addEventListener("click", function () {
        jeeDialog.confirm("{{Supprimer définitivement ce profil et ses clés locales ? L’opération est refusée tant qu’un équipement l’utilise.}}", function (confirmed) {
          if (!confirmed) return;
          request("removeProfile", { profileId: profile.profileId }, function () {
            jeedomUtils.showAlert({ message: "{{Profil supprimé}}", level: "success" });
            loadProfiles();
          });
        });
      });
      actions.appendChild(remove);
    });
  }

  function loadProfiles() {
    request("profiles", {}, renderProfiles);
  }

  function openBrowserAuthorization() {
    jeeDialog.dialog({
      id: "md_localhomeconnect_profile_authorization",
      title: "{{Autorisation Home Connect avec le navigateur}}",
      width: "760px",
      height: "620px",
      contentUrl: "index.php?v=d&plugin=localhomeconnect&modal=profile.authorization",
    });
  }

  document.getElementById("bt_localhomeconnect_download_profiles")?.addEventListener("click", function () {
    const button = this;
    const originalContent = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-sync fa-spin"></i> {{Connexion et téléchargement...}}';
    const form = new URLSearchParams();
    form.append("action", "downloadProfilesFromHomeconnect");
    fetch(endpoint, {
      method: "POST",
      body: form,
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
    })
      .then(function (response) { return response.json(); })
      .then(function (response) {
        if (response.state !== "ok") {
          throw new Error(String(response.result || "{{La récupération directe a échoué}}"));
        }
        const result = response.result || {};
        if (result.browserRequired === true) {
          jeedomUtils.showAlert({ message: String(result.message || "{{SingleKey exige le navigateur}}") + " — {{Poursuite avec la solution navigateur.}}", level: "warning" });
          openBrowserAuthorization();
          return;
        }
        const profiles = Array.isArray(result.profiles) ? result.profiles : [];
        jeedomUtils.showAlert({ message: profiles.length + " {{profil(s) et clé(s) récupéré(s)}}", level: "success" });
        loadProfiles();
      })
      .catch(showError)
      .finally(function () {
        button.disabled = false;
        button.innerHTML = originalContent;
      });
  });

  document.getElementById("bt_localhomeconnect_browser_authorization")?.addEventListener("click", openBrowserAuthorization);

  window.addEventListener("localhomeconnect:profiles-updated", loadProfiles);

  document.getElementById("bt_localhomeconnect_import")?.addEventListener("click", function () {
    const input = document.getElementById("in_localhomeconnect_profile");
    if (!input || !input.files || !input.files[0]) {
      jeedomUtils.showAlert({ message: "{{Sélectionnez une archive ZIP}}", level: "warning" });
      return;
    }
    const button = this;
    const data = new FormData();
    data.append("action", "importProfiles");
    data.append("profile", input.files[0]);
    button.disabled = true;
    fetch(endpoint, { method: "POST", body: data, credentials: "same-origin" })
      .then(function (response) { return response.json(); })
      .then(function (response) {
        if (response.state !== "ok") throw new Error(response.result);
        input.value = "";
        jeedomUtils.showAlert({ message: response.result.length + " {{profil(s) importé(s)}}", level: "success" });
        loadProfiles();
      })
      .catch(showError)
      .finally(function () { button.disabled = false; });
  });

  loadProfiles();
})();

/* Signale les champs de configuration modifiés mais non enregistrés. */
function printPluginConfiguration() {
  const form = document.getElementById("configuration_plugin_localhomeconnect");
  const save = document.getElementById("bt_savePluginConfig");
  if (!form || !save || form.hasAttribute("data-change-tracked")) return;
  form.setAttribute("data-change-tracked", "1");
  const inputs = Array.from(form.querySelectorAll(".configKey"));
  const initial = new Map();
  const message = document.createElement("i");
  message.className = "modificationWithoutSave label label-warning pull-right localhomeconnect-modification-message";
  message.textContent = "{{Modification en cours...}}";
  message.style.display = "none";
  save.parentNode.insertBefore(message, save.nextSibling);

  function value(input) { return input.type === "checkbox" ? input.checked : input.value; }
  function update() {
    const changed = inputs.some(function (input) { return value(input) !== initial.get(input); });
    message.style.display = changed ? "inline-block" : "none";
    inputs.forEach(function (input) {
      const modified = value(input) !== initial.get(input);
      input.classList.toggle("localhomeconnect-field-modified", modified);
    });
  }
  inputs.forEach(function (input) {
    initial.set(input, value(input));
    input.addEventListener(input.type === "checkbox" || input.tagName === "SELECT" ? "change" : "input", update);
  });
  save.addEventListener("click", function () {
    window.setTimeout(function () {
      inputs.forEach(function (input) { initial.set(input, value(input)); });
      update();
    }, 200);
  });
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", printPluginConfiguration);
else window.setTimeout(printPluginConfiguration, 100);
