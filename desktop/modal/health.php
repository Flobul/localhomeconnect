<?php

if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
}

/**
 * Échappe une valeur avant son insertion dans la page Santé.
 *
 * @param mixed $value Valeur brute.
 * @return string
 */
function localhomeconnectHealthEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Présente une durée du démon sous une forme compacte.
 *
 * @param int $seconds Durée en secondes.
 * @return string
 */
function localhomeconnectHealthDuration($seconds)
{
    $seconds = max(0, (int) $seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return $days . ' j ' . $hours . ' h';
    }
    if ($hours > 0) {
        return $hours . ' h ' . $minutes . ' min';
    }
    return $minutes . ' min';
}

$checks = localhomeconnect::health();
$daemon = localhomeconnect::deamon_info();
$runtimeByHaId = array();
foreach ((array) ($daemon['devices'] ?? array()) as $runtime) {
    if (is_array($runtime) && isset($runtime['haId'])) {
        $runtimeByHaId[(string) $runtime['haId']] = $runtime;
    }
}
$profileById = array();
foreach (localhomeconnect::profiles() as $profile) {
    if (isset($profile['profileId'])) {
        $profileById[(string) $profile['profileId']] = $profile;
    }
}
$eqLogics = localhomeconnect::byType('localhomeconnect');
$online = 0;
$enabled = 0;
foreach ($eqLogics as $eqLogic) {
    if (!$eqLogic->getIsEnable()) {
        continue;
    }
    $enabled++;
    $runtime = $runtimeByHaId[(string) $eqLogic->getConfiguration('ha_id', '')] ?? array();
    if (!empty($runtime['connected'])) {
        $online++;
    }
}
?>
<?php include_file('desktop', 'health', 'css', 'localhomeconnect'); ?>
<div id="div_healthLocalHomeConnect" class="localhomeconnect-health">
    <header class="localhomeconnect-health-hero">
        <div class="localhomeconnect-health-hero-icon"><i class="fas fa-heartbeat"></i></div>
        <div class="localhomeconnect-health-hero-copy">
            <span class="localhomeconnect-health-eyebrow">{{Diagnostic du plugin}}</span>
            <h2>{{Santé LocalHomeConnect}}</h2>
            <p>{{Connexions locales, état du démon et dernières communications de vos appareils.}}</p>
        </div>
        <button type="button" class="btn localhomeconnect-health-refresh" id="bt_refreshHealthLocalHomeConnect"><i class="fas fa-sync-alt"></i> {{Rafraîchir}}</button>
    </header>
    <section class="localhomeconnect-health-kpis" aria-label="{{Résumé de la santé LocalHomeConnect}}">
        <article class="localhomeconnect-health-kpi localhomeconnect-health-kpi-equipment">
            <span class="localhomeconnect-health-kpi-icon"><i class="fas fa-plug"></i></span>
            <div><span class="localhomeconnect-health-kpi-label">{{Équipements}}</span><strong><?=count($eqLogics)?></strong><small>{{configuré(s) dans Jeedom}}</small></div>
        </article>
        <article class="localhomeconnect-health-kpi localhomeconnect-health-kpi-enabled">
            <span class="localhomeconnect-health-kpi-icon"><i class="fas fa-power-off"></i></span>
            <div><span class="localhomeconnect-health-kpi-label">{{Équipements actifs}}</span><strong><?=$enabled?></strong><small>{{activé(s) dans Jeedom}}</small></div>
        </article>
        <article class="localhomeconnect-health-kpi localhomeconnect-health-kpi-online">
            <span class="localhomeconnect-health-kpi-icon"><i class="fas fa-wifi"></i></span>
            <div><span class="localhomeconnect-health-kpi-label">{{Prêts}}</span><strong><?=$online?> <small>/ <?=$enabled?></small></strong><small>{{équipement(s) en ligne}}</small></div>
        </article>
        <article class="localhomeconnect-health-kpi localhomeconnect-health-kpi-offline">
            <span class="localhomeconnect-health-kpi-icon"><i class="fas fa-exclamation-circle"></i></span>
            <div><span class="localhomeconnect-health-kpi-label">{{Hors ligne}}</span><strong><?=max(0, $enabled - $online)?></strong><small>{{équipement(s) actifs à vérifier}}</small></div>
        </article>
    </section>
    <?php if ((string) ($daemon['state'] ?? 'nok') === 'ok') { ?>
        <div class="localhomeconnect-health-daemon">
            <span class="localhomeconnect-health-badge is-success"><i class="fas fa-check-circle"></i> {{Démon démarré}}</span>
            <span><i class="fas fa-microchip"></i> Node.js <?=localhomeconnectHealthEscape($daemon['node_version'] ?? '—')?></span>
            <span><i class="fas fa-stopwatch"></i> {{Démarré depuis}} <?=localhomeconnectHealthEscape(localhomeconnectHealthDuration($daemon['uptime_seconds'] ?? 0))?></span>
            <span><i class="fas fa-memory"></i> <?=(int) ($daemon['memory_rss_mb'] ?? 0)?> Mo</span>
            <span><i class="fas fa-fingerprint"></i> PID <?=(int) ($daemon['pid'] ?? 0)?></span>
        </div>
    <?php } else { ?>
        <div class="localhomeconnect-health-daemon"><span class="localhomeconnect-health-badge is-danger"><i class="fas fa-exclamation-circle"></i> {{Démon arrêté}}</span><span>{{Consultez les contrôles du plugin pour vérifier la communication locale.}}</span></div>
    <?php } ?>
    <section class="localhomeconnect-health-panel">
        <div class="localhomeconnect-health-toolbar">
            <div class="localhomeconnect-health-tabs" role="tablist" aria-label="{{Informations de santé}}">
                <button type="button" id="localhomeconnectHealthEquipmentTab" class="localhomeconnect-health-tab is-active" data-health-view="equipment" role="tab" aria-selected="true" aria-controls="localhomeconnectHealthEquipmentPanel" tabindex="0"><i class="fas fa-plug"></i> {{Équipements}} <span><?=count($eqLogics)?></span></button>
                <button type="button" id="localhomeconnectHealthChecksTab" class="localhomeconnect-health-tab" data-health-view="checks" role="tab" aria-selected="false" aria-controls="localhomeconnectHealthChecksPanel" tabindex="-1"><i class="fas fa-heartbeat"></i> {{Contrôles du plugin}} <span><?=count($checks)?></span></button>
            </div>
            <div class="localhomeconnect-health-tools">
                <span class="localhomeconnect-health-result-count" data-health-result-count aria-live="polite"></span>
                <label class="localhomeconnect-health-search" for="localhomeconnectHealthSearch"><i class="fas fa-search"></i><input id="localhomeconnectHealthSearch" type="search" class="form-control" placeholder="{{Rechercher un équipement}}" aria-label="{{Rechercher dans la vue active}}" autocomplete="off"></label>
                <button type="button" class="localhomeconnect-health-clear" data-health-clear hidden title="{{Effacer la recherche}}" aria-label="{{Effacer la recherche}}"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div id="localhomeconnectHealthEquipmentPanel" class="localhomeconnect-health-view is-active" data-health-panel="equipment" role="tabpanel" aria-labelledby="localhomeconnectHealthEquipmentTab">
            <?php if (count($eqLogics) === 0) { ?>
                <div class="localhomeconnect-health-empty"><i class="fas fa-plug"></i><strong>{{Aucun équipement LocalHomeConnect}}</strong><span>{{Importez vos profils puis lancez une découverte depuis la page des équipements.}}</span></div>
            <?php } else { ?>
                <div class="localhomeconnect-health-table-wrap">
                    <table class="table localhomeconnect-health-table">
                        <thead><tr>
                            <th aria-sort="none"><button type="button" data-health-sort="name">{{Appareil}} <i class="fas fa-sort"></i></button></th>
                            <th aria-sort="none"><button type="button" data-health-sort="status" data-sort-type="number">{{État}} <i class="fas fa-sort"></i></button></th>
                            <th aria-sort="none"><button type="button" data-health-sort="host">{{Connexion locale}} <i class="fas fa-sort"></i></button></th>
                            <th aria-sort="none"><button type="button" data-health-sort="activity">{{Activité}} <i class="fas fa-sort"></i></button></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($eqLogics as $eqLogic) {
                            $haId = (string) $eqLogic->getConfiguration('ha_id', '');
                            $runtime = $runtimeByHaId[$haId] ?? array();
                            $isEnabled = (bool) $eqLogic->getIsEnable();
                            $isOnline = $isEnabled && !empty($runtime['connected']);
                            $profile = $profileById[(string) $eqLogic->getConfiguration('profile_id', '')] ?? array();
                            $stateLabel = !$isEnabled ? '{{Désactivé}}' : ($isOnline ? '{{Prêt}}' : (!empty($runtime['connecting']) ? '{{Connexion}}' : '{{Hors ligne}}'));
                            $stateClass = !$isEnabled ? 'muted' : ($isOnline ? 'success' : (!empty($runtime['connecting']) ? 'warning' : 'danger'));
                            $stateIcon = !$isEnabled ? 'fa-pause-circle' : ($isOnline ? 'fa-wifi' : (!empty($runtime['connecting']) ? 'fa-sync-alt' : 'fa-exclamation-circle'));
                            $lastSeen = $runtime['lastSeen'] ?? $eqLogic->getStatus('lastCommunication', '') ?: '—';
                            $host = $eqLogic->getConfiguration('host', '');
                            $searchValue = implode(' ', array($eqLogic->getName(), $eqLogic->getDeviceTypeLabel(), $eqLogic->getConfiguration('model', ''), $host, $haId, $stateLabel));
                        ?>
                            <tr data-health-item data-search-value="<?=localhomeconnectHealthEscape($searchValue)?>" data-sort-name="<?=localhomeconnectHealthEscape($eqLogic->getName())?>" data-sort-status="<?=($isEnabled ? 2 : 0) + ($isOnline ? 1 : 0)?>" data-sort-host="<?=localhomeconnectHealthEscape($host)?>" data-sort-activity="<?=localhomeconnectHealthEscape($lastSeen)?>" class="<?=$isEnabled ? '' : 'is-disabled'?>">
                                <td data-label="{{Appareil}}">
                                    <div class="localhomeconnect-health-device">
                                        <span class="localhomeconnect-health-device-icon"><i class="fas fa-plug"></i></span>
                                        <div><a href="<?=localhomeconnectHealthEscape($eqLogic->getLinkToConfiguration())?>"><?=localhomeconnectHealthEscape($eqLogic->getName())?></a><span><?=localhomeconnectHealthEscape($eqLogic->getDeviceTypeLabel())?></span><small><?=localhomeconnectHealthEscape($eqLogic->getConfiguration('model', ''))?></small></div>
                                    </div>
                                </td>
                                <td data-label="{{État}}">
                                    <div class="localhomeconnect-health-badges"><span class="localhomeconnect-health-badge is-<?=$stateClass?>"><i class="fas <?=$stateIcon?>"></i> <?=$stateLabel?></span></div>
                                    <dl class="localhomeconnect-health-details">
                                        <div><dt>{{Données}}</dt><dd><?=(int) ($runtime['entities'] ?? $runtime['states'] ?? 0)?> {{états}} · <?=(int) ($runtime['writable'] ?? 0)?> {{inscriptibles}}</dd></div>
                                        <div><dt>{{Reconnexions}}</dt><dd><?=(int) ($runtime['reconnectFailures'] ?? 0)?></dd></div>
                                    </dl>
                                </td>
                                <td data-label="{{Connexion locale}}">
                                    <dl class="localhomeconnect-health-details">
                                        <div><dt>{{Adresse}}</dt><dd><code><?=localhomeconnectHealthEscape($host)?></code></dd></div>
                                        <div><dt>{{Transport}}</dt><dd><?=localhomeconnectHealthEscape($runtime['transport'] ?? $eqLogic->getConfiguration('connection_type', ''))?></dd></div>
                                        <div><dt>{{Profil importé le}}</dt><dd><?=localhomeconnectHealthEscape($profile['importedAt'] ?? '—')?></dd></div>
                                    </dl>
                                    <button type="button" class="btn btn-default btn-xs localhomeconnect-health-test bt_testHealthLocalHomeConnect" data-eqlogic_id="<?=(int) $eqLogic->getId()?>" title="{{Tester la communication}}"><i class="fas fa-satellite-dish"></i> {{Tester la communication}}</button>
                                </td>
                                <td data-label="{{Activité}}">
                                    <dl class="localhomeconnect-health-details">
                                        <div><dt>{{Dernière communication}}</dt><dd><?=localhomeconnectHealthEscape($lastSeen)?></dd></div>
                                        <div><dt>{{Dernière erreur}}</dt><dd class="text-danger"><?=localhomeconnectHealthEscape($runtime['lastError'] ?? $eqLogic->getConfiguration('last_error', '') ?: '—')?></dd></div>
                                    </dl>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <p class="localhomeconnect-health-no-result" data-health-no-result hidden><i class="fas fa-search"></i> {{Aucun équipement ne correspond à la recherche.}}</p>
            <?php } ?>
        </div>
        <div id="localhomeconnectHealthChecksPanel" class="localhomeconnect-health-view" data-health-panel="checks" role="tabpanel" aria-labelledby="localhomeconnectHealthChecksTab" hidden>
            <div class="localhomeconnect-health-table-wrap">
                <table class="table localhomeconnect-health-table localhomeconnect-health-checks">
                    <thead><tr><th><span>{{Contrôle}}</span></th><th><span>{{État}}</span></th><th><span>{{Détail}}</span></th><th><span>{{Conseil}}</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($checks as $row) { ?>
                        <tr data-health-item data-search-value="<?=localhomeconnectHealthEscape(implode(' ', array($row['test'], $row['result'], $row['advice'])))?>">
                            <td data-label="{{Contrôle}}"><strong><?=localhomeconnectHealthEscape($row['test'])?></strong></td>
                            <td data-label="{{État}}"><span class="localhomeconnect-health-badge is-<?=!empty($row['state']) ? 'success' : 'danger'?>"><i class="fas <?=!empty($row['state']) ? 'fa-check-circle' : 'fa-exclamation-circle'?>"></i> <?=!empty($row['state']) ? 'OK' : 'NOK'?></span></td>
                            <td data-label="{{Détail}}"><?=localhomeconnectHealthEscape($row['result'])?></td>
                            <td data-label="{{Conseil}}"><?=localhomeconnectHealthEscape($row['advice'])?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <p class="localhomeconnect-health-no-result" data-health-no-result hidden><i class="fas fa-search"></i> {{Aucun contrôle ne correspond à la recherche.}}</p>
        </div>
    </section>
</div>
<?php include_file('desktop', 'health', 'js', 'localhomeconnect'); ?>
