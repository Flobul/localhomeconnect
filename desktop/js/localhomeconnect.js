"use strict";

(function () {
  const root = document.getElementById("div_localhomeconnect");
  const endpoint = "plugins/localhomeconnect/core/ajax/localhomeconnect.ajax.php";
  if (!root) return;

  function request(action, data, success, failure) {
    domUtils.ajax({
      type: "POST", url: endpoint, dataType: "json",
      data: Object.assign({ action: action }, data || {}),
      error: function (request, status, error) { handleAjaxError(request, status, error); if (failure) failure(error); },
      success: function (response) {
        if (response.state !== "ok") { jeedomUtils.showAlert({ message: response.result, level: "danger" }); if (failure) failure(response.result); return; }
        if (success) success(response.result);
      },
    });
  }

  root.addEventListener("click", function (event) {
    const commandConfigure = event.target.closest('.cmdAttr[data-action="configureCommand"]');
    if (commandConfigure) {
      const row = commandConfigure.closest(".cmd");
      const commandId = row ? row.getAttribute("data-cmd_id") : "";
      if (commandId) {
        jeeDialog.dialog({
          id: "md_localhomeconnectCommandConfigure",
          title: "{{Modification de la commande}}",
          contentUrl: "index.php?v=d&plugin=localhomeconnect&modal=command.configure&id=" + encodeURIComponent(commandId),
        });
      }
      return;
    }
    if (event.target.closest("#bt_discoverLocalHomeConnect")) {
      request("synchronize", {}, function (result) {
        const count = Array.isArray(result.devices) ? result.devices.length : 0;
        jeedomUtils.showAlert({ message: count + " {{service(s) Home Connect détecté(s)}}", level: count ? "success" : "info" });
        window.setTimeout(function () { window.location.reload(); }, 900);
      });
      return;
    }
    if (event.target.closest("#bt_healthLocalHomeConnect")) {
      jeeDialog.dialog({ id: "md_localhomeconnect_health", title: "{{Santé LocalHomeConnect}}", contentUrl: "index.php?v=d&plugin=localhomeconnect&modal=health" });
      return;
    }
    if (event.target.closest("#bt_resetSearch")) {
      const input = document.getElementById("in_searchEqlogic");
      if (input) { input.value = ""; input.dispatchEvent(new Event("keyup")); }
      return;
    }
    const id = document.querySelector('.eqLogicAttr[data-l1key="id"]')?.jeeValue();
    if (!id) return;
    if (event.target.closest("#bt_refreshLocalHomeConnect")) {
      request("refresh", { id: id }, function () { jeedomUtils.showAlert({ message: "{{Informations locales actualisées}}", level: "success" }); });
      return;
    }
    const test = event.target.closest("#bt_testCommunicationLocalHomeConnect");
    if (test) {
      test.disabled = true;
      test.querySelector("i")?.classList.add("fa-spin");
      const reset = function () { test.disabled = false; test.querySelector("i")?.classList.remove("fa-spin"); };
      request("testCommunication", { id: id }, function (result) {
        reset();
        jeedomUtils.showAlert({ message: "{{Communication locale réussie en}} " + Number(result.duration_ms || 0) + " ms", level: "success" });
      }, reset);
    }
  });

  document.getElementById("in_searchEqlogic")?.addEventListener("keyup", function () {
    const search = this.value.toLowerCase().trim();
    root.querySelectorAll(".eqLogicDisplayCard").forEach(function (card) {
      card.style.display = !search || card.textContent.toLowerCase().includes(search) ? "" : "none";
    });
  });
})();

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) _cmd = { configuration: {} };
  if (!isset(_cmd.configuration)) _cmd.configuration = {};
  let html = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
  html += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>';
  html += '<td><div class="input-group"><input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name"><span class="input-group-btn"><a class="btn btn-default btn-sm cmdAction roundedRight" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span></div>';
  if (Number(init(_cmd.configuration.obsolete)) === 1) html += '<span class="label label-warning"><i class="fas fa-archive"></i> {{Obsolète — absente ou indisponible dans le profil actuel}}</span> ';
  if (Number(init(_cmd.configuration.requires_opt_in)) === 1 && Number(init(_cmd.configuration.manual_override)) !== 1) html += '<span class="label label-info"><i class="fas fa-user-shield"></i> {{Action XML inconnue — activation manuelle requise}}</span> ';
  html += '<span class="cmdAttr" data-l1key="display" data-l2key="icon"></span><select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none"><option value="">{{Aucune}}</option></select></td>';
  html += '<td><span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span><span class="subType" subType="' + init(_cmd.subType) + '"></span></td>';
  html += '<td><label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible">{{Afficher}}</label> <label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>';
  if (init(_cmd.subType) === "numeric" || init(_cmd.subType) === "slider") html += '<div class="input-group"><input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}"><input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}"><input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}"></div>';
  if (init(_cmd.subType) === "select") html += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="listValue" placeholder="{{Valeur|Libellé;...}}">';
  html += '</td><td>' + (init(_cmd.type) === "info" ? '<span class="cmdAttr" data-l1key="htmlstate"></span>' : '') + '</td><td><div class="input-group" style="display:inline-flex">';
  if (is_numeric(_cmd.id) && _cmd.id !== "") html += '<a class="btn btn-default btn-xs cmdAction roundedLeft" data-action="configure" title="{{Configuration de la commande}}"><i class="fas fa-cogs"></i></a><a class="btn btn-warning btn-xs cmdAttr" data-action="configureCommand" title="{{Modification de la commande}}"><i class="fas fa-wrench"></i></a><a class="btn btn-success btn-xs cmdAction" data-action="test" title="{{Tester}}"><i class="fas fa-rss"></i></a>';
  html += '<a class="btn btn-danger btn-xs cmdAction roundedRight" data-action="remove" title="{{Supprimer}}"><i class="fas fa-minus-circle"></i></a></div></td></tr>';
  const holder = document.createElement("tbody");
  holder.innerHTML = html;
  const row = holder.firstElementChild;
  document.querySelector("#table_cmd tbody").appendChild(row);
  jeedom.eqLogic.buildSelectCmd({
    id: document.querySelector('.eqLogicAttr[data-l1key="id"]')?.jeeValue(), filter: { type: "info" },
    error: function (error) { jeedomUtils.showAlert({ message: error.message, level: "danger" }); },
    success: function (result) {
      row.querySelector('.cmdAttr[data-l1key="value"]')?.insertAdjacentHTML("beforeend", result);
      row.setJeeValues(_cmd, ".cmdAttr");
      jeedom.cmd.changeType(row, init(_cmd.subType));
    },
  });
}
