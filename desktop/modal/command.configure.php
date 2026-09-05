<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify it under the
 * terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or any later version.
 */

if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
}

$command = cmd::byId((int) init('id'));
if (!is_object($command)) {
    throw new Exception(__('Commande LocalHomeConnect introuvable', __FILE__));
}
$eqLogic = $command->getEqLogic();
if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'localhomeconnect') {
    throw new Exception(__('Équipement LocalHomeConnect introuvable', __FILE__));
}

$commandInfo = jeedom::toHumanReadable(utils::o2a($command));
$commandInfo['eqLogicName'] = $eqLogic->getName();
$xmlDefinition = array();
$xmlError = '';
try {
    $profileId = trim((string) $eqLogic->getConfiguration('profile_id', ''));
    $uidHex = trim((string) $command->getConfiguration('uid_hex', ''));
    if ($uidHex === '' && is_numeric($command->getConfiguration('uid', ''))) {
        $uidHex = strtoupper(str_pad(dechex((int) $command->getConfiguration('uid')), 4, '0', STR_PAD_LEFT));
    }
    if ($profileId === '' || $uidHex === '') {
        throw new RuntimeException(__('Cette commande ne possède pas de correspondance XML', __FILE__));
    }
    $xmlDefinition = localhomeconnect::profileStore()->getFeatureDefinition($profileId, $uidHex);
} catch (Exception $exception) {
    $xmlError = displayException($exception);
}

sendVarToJS('localhomeconnectCommandInfo', $commandInfo);
sendVarToJS('localhomeconnectXmlDefinition', $xmlDefinition);

/**
 * Échappe une valeur destinée au HTML.
 *
 * @param mixed $value Valeur.
 * @return string
 */
function localhomeconnectCommandEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<link rel="stylesheet" href="core/php/getResource.php?file=/plugins/localhomeconnect/desktop/css/localhomeconnect.css">

<div id="div_localhomeconnectCommandConfigure" class="localhomeconnect-command-editor">
    <div class="localhomeconnect-command-toolbar">
        <button type="button" class="btn btn-default" id="bt_localhomeconnectCommandReset" <?php echo (int) $command->getConfiguration('manual_override', 0) !== 1 ? 'disabled' : ''; ?>>
            <i class="fas fa-undo"></i> {{Revenir aux valeurs du XML}}
        </button>
        <button type="button" class="btn btn-success" id="bt_localhomeconnectCommandSave">
            <i class="fas fa-check-circle"></i> {{Enregistrer les modifications}}
        </button>
    </div>

    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        {{Une modification manuelle est conservée lors des prochains rafraîchissements. Utilisez « Revenir aux valeurs du XML » pour reprendre la génération automatique du plugin.}}
    </div>

    <?php if ((int) $command->getConfiguration('requires_opt_in', 0) === 1 && (int) $command->getConfiguration('manual_override', 0) !== 1) { ?>
    <div class="alert alert-info">
        <i class="fas fa-user-shield"></i>
        {{Cette action est annoncée comme modifiable par le XML, mais sa fonction n’est pas encore classée comme sûre par le plugin. Vérifiez les informations XML ci-dessous : l’enregistrement de cette commande constitue son activation explicite.}}
    </div>
    <?php } ?>

    <div class="localhomeconnect-command-grid">
        <section class="localhomeconnect-command-panel">
            <header><i class="fas fa-wrench"></i><div><h3>{{Commande Jeedom modifiable}}</h3><p>{{Valeurs réellement enregistrées dans la commande.}}</p></div></header>
            <form class="form-horizontal" onsubmit="return false;">
                <div class="form-group">
                    <label class="col-sm-4 control-label">{{Nom}}</label>
                    <div class="col-sm-8"><input type="text" class="form-control" id="in_lhcCommandName"></div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{{Type}}</label>
                    <div class="col-sm-8"><span class="label label-primary localhomeconnect-command-type" id="sp_lhcCommandType"></span></div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{{Sous-type}}</label>
                    <div class="col-sm-8">
                        <select class="form-control" id="sel_lhcCommandSubtype"></select>
                        <p class="help-block" id="sp_lhcSubtypeHelp"></p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{{Unité}}</label>
                    <div class="col-sm-8"><input type="text" class="form-control" id="in_lhcCommandUnit" placeholder="{{Aucune unité}}"></div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{{Affichage}}</label>
                    <div class="col-sm-8">
                        <label class="checkbox-inline"><input type="checkbox" id="cb_lhcCommandVisible"> {{Visible}}</label>
                        <label class="checkbox-inline"><input type="checkbox" id="cb_lhcCommandHistorized"> {{Historiser}}</label>
                    </div>
                </div>
            </form>
        </section>

        <section class="localhomeconnect-command-panel">
            <header><i class="fas fa-code"></i><div><h3>{{Correspondance LocalHomeConnect}}</h3><p>{{Configuration utilisée pour lire ou envoyer la valeur.}}</p></div></header>
            <form class="form-horizontal" onsubmit="return false;">
                <div class="form-group"><label class="col-sm-4 control-label">{{UID numérique}}</label><div class="col-sm-8"><input type="number" min="0" class="form-control" id="in_lhcCommandUid"></div></div>
                <div class="form-group"><label class="col-sm-4 control-label">{{UID hexadécimal}}</label><div class="col-sm-8"><input type="text" class="form-control text-uppercase" id="in_lhcCommandUidHex"></div></div>
                <div class="form-group"><label class="col-sm-4 control-label">{{Fonction}}</label><div class="col-sm-8"><input type="text" class="form-control" id="in_lhcCommandFeature"></div></div>
                <div class="form-group"><label class="col-sm-4 control-label">{{Catégorie}}</label><div class="col-sm-8"><input type="text" class="form-control" id="in_lhcCommandCategory" list="list_lhcCategories"><datalist id="list_lhcCategories"><option value="status"><option value="settings"><option value="options"><option value="events"><option value="programs"><option value="information"></datalist></div></div>
                <div class="form-group"><label class="col-sm-4 control-label">{{Commande générée}}</label><div class="col-sm-8"><label class="checkbox-inline"><input type="checkbox" id="cb_lhcCommandGenerated"> {{Gérée par le plugin}}</label></div></div>
            </form>
        </section>
    </div>

    <section class="localhomeconnect-command-panel localhomeconnect-command-values">
        <header><i class="fas fa-sliders-h"></i><div><h3>{{Valeurs, liste et contraintes}}</h3><p>{{Présentation adaptée aux commandes numériques, binaires et aux listes.}}</p></div></header>
        <div id="div_lhcNumericConfiguration" class="localhomeconnect-command-inline-fields">
            <label>{{Minimum}}<input type="number" step="any" class="form-control" id="in_lhcCommandMin"></label>
            <label>{{Maximum}}<input type="number" step="any" class="form-control" id="in_lhcCommandMax"></label>
            <label>{{Pas}}<input type="number" step="any" min="0" class="form-control" id="in_lhcCommandStep"></label>
        </div>
        <div id="div_lhcBinaryConfiguration" class="alert alert-info localhomeconnect-command-type-notice">
            <i class="fas fa-toggle-on"></i> {{Cette commande est binaire : 0 correspond à l’état désactivé et 1 à l’état activé.}}
        </div>
        <div class="table-responsive">
            <table class="table table-condensed localhomeconnect-command-state-table">
                <thead><tr><th>{{Valeur brute}}</th><th>{{Libellé affiché}}</th><th class="text-center">{{Retirer}}</th></tr></thead>
                <tbody id="tb_lhcCommandStates"></tbody>
            </table>
        </div>
        <button type="button" class="btn btn-default btn-sm" id="bt_lhcCommandStateAdd"><i class="fas fa-plus-circle"></i> {{Ajouter une valeur}}</button>
    </section>

    <?php if ($command->getType() === 'action') { ?>
    <section class="localhomeconnect-command-panel" id="div_lhcActionConfiguration">
        <header><i class="fas fa-paper-plane"></i><div><h3>{{Envoi de l’action}}</h3><p>{{Paramètres transmis au démon local.}}</p></div></header>
        <div class="localhomeconnect-command-inline-fields">
            <label>{{Opération}}<input type="text" class="form-control" id="in_lhcCommandOperation" list="list_lhcOperations"><datalist id="list_lhcOperations"><option value="write"><option value="select_program"><option value="start_program"><option value="refresh"></datalist></label>
            <label>{{Valeur imposée}}<input type="text" class="form-control" id="in_lhcCommandFixedValue"></label>
            <label class="localhomeconnect-command-checkbox"><input type="checkbox" id="cb_lhcCommandHasFixedValue"> {{Utiliser la valeur imposée}}</label>
        </div>
    </section>
    <?php } ?>

    <section class="localhomeconnect-command-panel localhomeconnect-command-xml">
        <header><i class="fas fa-file-code"></i><div><h3>{{Informations du profil XML}}</h3><p>{{Relues directement dans les fichiers privés du dossier /data/. Ces valeurs sont en lecture seule.}}</p></div></header>
        <?php if ($xmlError !== '') { ?>
            <div class="alert alert-warning"><i class="fas fa-info-circle"></i> <?php echo localhomeconnectCommandEscape($xmlError); ?></div>
        <?php } else { ?>
            <div class="localhomeconnect-xml-paths">
                <code><?php echo localhomeconnectCommandEscape($xmlDefinition['device_description'] ?? ''); ?></code>
                <code><?php echo localhomeconnectCommandEscape($xmlDefinition['feature_mapping'] ?? ''); ?></code>
            </div>
            <div class="localhomeconnect-xml-summary">
                <span><small>{{UID}}</small><strong><?php echo localhomeconnectCommandEscape($xmlDefinition['uid_hex'] ?? ''); ?></strong></span>
                <span><small>{{Élément XML}}</small><strong>&lt;<?php echo localhomeconnectCommandEscape($xmlDefinition['node'] ?? ''); ?>&gt;</strong></span>
                <span><small>{{Fonction}}</small><strong><?php echo localhomeconnectCommandEscape($xmlDefinition['feature'] ?? ''); ?></strong></span>
                <span><small>{{Type détecté}}</small><strong><?php echo !empty($xmlDefinition['boolean']) ? '{{Binaire}}' : (!empty($xmlDefinition['enum_type']) ? '{{Liste}}' : '{{Valeur simple}}'); ?></strong></span>
            </div>
            <div class="table-responsive"><table class="table table-condensed">
                <thead><tr><th>{{Attribut XML}}</th><th>{{Valeur XML}}</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($xmlDefinition['attributes'] ?? array()) as $attribute => $value) { ?>
                    <tr><td><code><?php echo localhomeconnectCommandEscape($attribute); ?></code></td><td><?php echo localhomeconnectCommandEscape($value); ?></td></tr>
                <?php } ?>
                </tbody>
            </table></div>
            <?php if (count((array) ($xmlDefinition['states'] ?? array())) > 0) { ?>
                <h4><i class="fas fa-list"></i> {{Énumération définie dans FeatureMapping.xml}}</h4>
                <div class="table-responsive"><table class="table table-condensed"><thead><tr><th>{{Valeur}}</th><th>{{Identifiant XML}}</th></tr></thead><tbody>
                <?php foreach ((array) $xmlDefinition['states'] as $raw => $label) { ?>
                    <tr><td><code><?php echo localhomeconnectCommandEscape($raw); ?></code></td><td><?php echo localhomeconnectCommandEscape($label); ?></td></tr>
                <?php } ?>
                </tbody></table></div>
            <?php } ?>
        <?php } ?>
    </section>

    <details class="localhomeconnect-command-json">
        <summary><i class="fas fa-code"></i> {{Voir la configuration JSON actuelle}}</summary>
        <pre><?php echo localhomeconnectCommandEscape(json_encode($command->getConfiguration(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
    </details>
</div>

<script>
(function () {
  "use strict";
  const editor = document.getElementById("div_localhomeconnectCommandConfigure");
  if (!editor || typeof localhomeconnectCommandInfo !== "object") return;
  let command = JSON.parse(JSON.stringify(localhomeconnectCommandInfo));
  command.configuration = command.configuration || {};

  const byId = function (id) { return document.getElementById(id); };
  const subtypeOptions = {
    info: { string: "{{Texte}}", numeric: "{{Numérique}}", binary: "{{Binaire}}" },
    action: { other: "{{Défaut}}", select: "{{Liste}}", slider: "{{Curseur}}", message: "{{Message}}", color: "{{Couleur}}" },
  };

  function setValue(id, value) {
    const input = byId(id);
    if (input) input.value = value === undefined || value === null ? "" : value;
  }

  function addState(raw, label) {
    const row = document.createElement("tr");
    row.innerHTML = '<td><input type="text" class="form-control input-sm lhc-state-raw"></td>'
      + '<td><input type="text" class="form-control input-sm lhc-state-label"></td>'
      + '<td class="text-center"><button type="button" class="btn btn-danger btn-xs bt-lhc-state-remove" title="{{Retirer}}"><i class="fas fa-minus-circle"></i></button></td>';
    row.querySelector(".lhc-state-raw").value = raw === undefined ? "" : raw;
    row.querySelector(".lhc-state-label").value = label === undefined ? "" : label;
    byId("tb_lhcCommandStates").appendChild(row);
  }

  function initialStates() {
    const states = command.configuration.states;
    if (states && typeof states === "object") {
      Object.keys(states).forEach(function (raw) { addState(raw, states[raw]); });
      return;
    }
    String(command.configuration.listValue || "").split(";").filter(Boolean).forEach(function (entry) {
      const separator = entry.indexOf("|");
      addState(separator < 0 ? entry : entry.slice(0, separator), separator < 0 ? entry : entry.slice(separator + 1));
    });
  }

  function refreshSubtype() {
    const subtype = byId("sel_lhcCommandSubtype").value;
    byId("div_lhcNumericConfiguration").style.display = ["numeric", "slider"].includes(subtype) ? "grid" : "none";
    byId("div_lhcBinaryConfiguration").style.display = subtype === "binary" ? "block" : "none";
    const labels = { binary: "{{Deux états : 0 ou 1.}}", numeric: "{{Valeur numérique avec bornes et unité facultatives.}}", string: "{{Texte ou état d’une énumération.}}", select: "{{Choix dans une liste de valeurs.}}", slider: "{{Valeur numérique réglable.}}", other: "{{Action sans paramètre fourni par l’utilisateur.}}", message: "{{Action avec une valeur textuelle.}}", color: "{{Action avec une couleur.}}" };
    byId("sp_lhcSubtypeHelp").textContent = labels[subtype] || "";
  }

  byId("sp_lhcCommandType").textContent = command.type;
  setValue("in_lhcCommandName", command.name);
  setValue("in_lhcCommandUnit", command.unite);
  byId("cb_lhcCommandVisible").checked = Number(command.isVisible) === 1;
  byId("cb_lhcCommandHistorized").checked = Number(command.isHistorized) === 1;
  const subtypeSelect = byId("sel_lhcCommandSubtype");
  Object.keys(subtypeOptions[command.type] || {}).forEach(function (value) {
    const option = document.createElement("option");
    option.value = value;
    option.textContent = subtypeOptions[command.type][value];
    subtypeSelect.appendChild(option);
  });
  subtypeSelect.value = command.subType;
  setValue("in_lhcCommandUid", command.configuration.uid);
  setValue("in_lhcCommandUidHex", command.configuration.uid_hex || (localhomeconnectXmlDefinition.uid_hex || ""));
  setValue("in_lhcCommandFeature", command.configuration.feature);
  setValue("in_lhcCommandCategory", command.configuration.category);
  byId("cb_lhcCommandGenerated").checked = Number(command.configuration.generated) === 1;
  setValue("in_lhcCommandMin", command.configuration.minValue);
  setValue("in_lhcCommandMax", command.configuration.maxValue);
  setValue("in_lhcCommandStep", command.configuration.step || command.display?.parameters?.step);
  setValue("in_lhcCommandOperation", command.configuration.operation);
  setValue("in_lhcCommandFixedValue", command.configuration.fixed_value);
  if (byId("cb_lhcCommandHasFixedValue")) byId("cb_lhcCommandHasFixedValue").checked = Number(command.configuration.has_fixed_value) === 1;
  initialStates();
  refreshSubtype();

  subtypeSelect.addEventListener("change", refreshSubtype);
  byId("bt_lhcCommandStateAdd").addEventListener("click", function () { addState("", ""); });
  byId("tb_lhcCommandStates").addEventListener("click", function (event) {
    event.target.closest(".bt-lhc-state-remove")?.closest("tr")?.remove();
  });

  function collectStates() {
    const states = {};
    byId("tb_lhcCommandStates").querySelectorAll("tr").forEach(function (row) {
      const raw = row.querySelector(".lhc-state-raw").value.trim();
      if (raw !== "") states[raw] = row.querySelector(".lhc-state-label").value.trim();
    });
    return states;
  }

  function optionalNumber(id) {
    const value = byId(id).value.trim();
    return value === "" ? "" : Number(value);
  }

  function save(resetToXml) {
    const updated = JSON.parse(JSON.stringify(command));
    updated.configuration = updated.configuration || {};
    if (resetToXml) {
      updated.configuration.manual_override = 0;
    } else {
      updated.name = byId("in_lhcCommandName").value.trim();
      updated.subType = subtypeSelect.value;
      updated.unite = byId("in_lhcCommandUnit").value.trim();
      updated.isVisible = byId("cb_lhcCommandVisible").checked ? 1 : 0;
      updated.isHistorized = byId("cb_lhcCommandHistorized").checked ? 1 : 0;
      updated.configuration.uid = Number(byId("in_lhcCommandUid").value || 0);
      updated.configuration.uid_hex = byId("in_lhcCommandUidHex").value.trim().toUpperCase();
      updated.configuration.feature = byId("in_lhcCommandFeature").value.trim();
      updated.configuration.category = byId("in_lhcCommandCategory").value.trim();
      updated.configuration.generated = byId("cb_lhcCommandGenerated").checked ? 1 : 0;
      updated.configuration.minValue = optionalNumber("in_lhcCommandMin");
      updated.configuration.maxValue = optionalNumber("in_lhcCommandMax");
      updated.configuration.step = optionalNumber("in_lhcCommandStep");
      const states = collectStates();
      if (updated.type === "info") updated.configuration.states = states;
      if (updated.type === "action" && updated.subType === "select") {
        updated.configuration.listValue = Object.keys(states).map(function (raw) { return raw.replace(/[|;]/g, "-") + "|" + states[raw].replace(/[|;]/g, "-"); }).join(";");
      }
      if (updated.type === "action") {
        updated.configuration.operation = byId("in_lhcCommandOperation").value.trim();
        updated.configuration.has_fixed_value = byId("cb_lhcCommandHasFixedValue").checked ? 1 : 0;
        updated.configuration.fixed_value = byId("in_lhcCommandFixedValue").value;
        if (updated.subType === "slider") {
          updated.display = updated.display || {};
          updated.display.parameters = updated.display.parameters || {};
          updated.display.parameters.step = updated.configuration.step;
        }
      }
      updated.configuration.manual_override = 1;
    }
    jeedom.cmd.save({
      cmd: updated,
      error: function (error) { jeedomUtils.showAlert({ message: error.message, level: "danger" }); },
      success: function (data) {
        command = data;
        jeedomUtils.showAlert({
          message: resetToXml ? "{{Surcharge supprimée. Rafraîchissez l’équipement pour relire le XML.}}" : "{{Modification de la commande enregistrée}}",
          level: "success",
        });
        if (resetToXml) byId("bt_localhomeconnectCommandReset").disabled = true;
        else byId("bt_localhomeconnectCommandReset").disabled = false;
      },
    });
  }

  byId("bt_localhomeconnectCommandSave").addEventListener("click", function () { save(false); });
  byId("bt_localhomeconnectCommandReset").addEventListener("click", function () {
    jeeDialog.confirm("{{Supprimer les modifications manuelles et reprendre les valeurs du profil XML au prochain rafraîchissement ?}}", function (confirmed) {
      if (confirmed) save(true);
    });
  });
})();
</script>
