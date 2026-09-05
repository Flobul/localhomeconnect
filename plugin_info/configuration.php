<?php

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../core/class/localhomeconnect.class.php';
include_file('core', 'authentification', 'php');

if (!isConnect('admin')) {
    include_file('desktop', '404', 'php');
    die();
}

$plugin = plugin::byId('localhomeconnect');
$update = $plugin->getUpdate();
sendVarToJS('localhomeconnectVersion', localhomeconnect::$_pluginVersion);
?>
<link rel="stylesheet" href="core/php/getResource.php?file=/plugins/localhomeconnect/desktop/css/localhomeconnect.css">

<?php include_file('desktop', 'configuration', 'css', 'localhomeconnect'); ?>

<form class="form-horizontal" id="configuration_plugin_localhomeconnect">
    <div class="localhomeconnect-config-page">
        <section class="localhomeconnect-config-hero">
            <div class="localhomeconnect-config-identity">
                <div class="localhomeconnect-config-logo"><img src="plugins/localhomeconnect/plugin_info/localhomeconnect_icon.png" alt="LocalHomeConnect"></div>
                <div>
                    <span class="localhomeconnect-config-eyebrow">{{Configuration du plugin}}</span>
                    <h2>LocalHomeConnect</h2>
                    <p>{{Connectez vos appareils Home Connect à Jeedom et pilotez-les sur votre réseau local.}}</p>
                </div>
            </div>
            <div class="localhomeconnect-config-meta">
                <span><i class="fas fa-code-branch"></i> {{Version}} <strong>v<?=htmlspecialchars(localhomeconnect::$_pluginVersion, ENT_QUOTES, 'UTF-8')?></strong></span>
                <?php if (is_object($update)) { ?>
                    <span><i class="fas fa-calendar-alt"></i> <?=htmlspecialchars((string) $update->getLocalVersion(), ENT_QUOTES, 'UTF-8')?></span>
                    <span><i class="fas fa-stream"></i> <?=htmlspecialchars((string) $update->getConfiguration('version', 'stable'), ENT_QUOTES, 'UTF-8')?></span>
                    <span><i class="fas fa-cloud-download-alt"></i> <?=htmlspecialchars((string) $update->getSource(), ENT_QUOTES, 'UTF-8')?></span>
                <?php } ?>
            </div>
            <div class="localhomeconnect-config-links">
                <a class="btn btn-sm localhomeconnect-config-link-documentation" target="_blank" rel="noopener noreferrer" href="<?=htmlspecialchars((string) $plugin->getDocumentation(), ENT_QUOTES, 'UTF-8')?>"><i class="fas fa-book"></i> {{Documentation}}</a>
                <a class="btn btn-sm localhomeconnect-config-link-changelog" target="_blank" rel="noopener noreferrer" href="<?=htmlspecialchars((string) $plugin->getChangelog(), ENT_QUOTES, 'UTF-8')?>"><i class="fas fa-list"></i> {{Changelog}}</a>
                <a class="btn btn-sm localhomeconnect-config-link-community" target="_blank" rel="noopener noreferrer" href="https://community.jeedom.com/tag/plugin-localhomeconnect"><i class="fas fa-comments"></i> {{Communauté}}</a>
            </div>
        </section>

        <div class="localhomeconnect-config-notices">
        <div class="alert alert-info">
            <i class="fas fa-network-wired"></i>
            {{Après l’import initial du profil, LocalHomeConnect communique directement avec l’appareil sur le réseau local, sans API cloud.}}
        </div>
        <div class="alert alert-warning">
            <i class="fas fa-key"></i>
            {{Le ZIP contient les clés locales de vos appareils. Conservez-le comme un secret et ne le publiez jamais. Les clés importées sont stockées avec des permissions restrictives.}}
        </div>

        </div>
        <div class="localhomeconnect-config-section-heading">
            <h3><i class="fas fa-cloud-download-alt"></i> {{Récupération des profils Home Connect}}</h3>
            <p>{{Choisissez une méthode pour installer les profils de vos appareils.}}</p>
        </div>
        <div class="localhomeconnect-profile-methods">
            <article class="localhomeconnect-profile-tile localhomeconnect-profile-tile-primary">
                <header>
                    <span class="localhomeconnect-profile-icon"><i class="fas fa-magic"></i></span>
                    <div>
                        <span class="label label-success">{{Recommandée}}</span>
                        <h3>{{Connexion automatique}}</h3>
                        <p>{{Jeedom tente de récupérer directement tous les profils associés au compte.}}</p>
                    </div>
                </header>
                <ol class="localhomeconnect-profile-steps">
                    <li>{{Renseignez le compte SingleKey ci-dessous.}}</li>
                    <li>{{Enregistrez la configuration Jeedom.}}</li>
                    <li>{{Lancez la récupération ; si hCaptcha intervient, la méthode navigateur s’ouvrira automatiquement.}}</li>
                </ol>
                <div class="localhomeconnect-tile-field">
                    <label for="in_localhomeconnect_username">{{Identifiant SingleKey}}</label>
                    <input id="in_localhomeconnect_username" type="email" autocomplete="username" class="configKey form-control" data-l1key="homeconnect_username" placeholder="adresse@email.com">
                </div>
                <div class="localhomeconnect-tile-field">
                    <label for="in_localhomeconnect_password">{{Mot de passe SingleKey}}</label>
                    <div class="input-group">
                        <input id="in_localhomeconnect_password" type="text" autocomplete="new-password" class="inputPassword configKey form-control" data-l1key="homeconnect_password" placeholder="{{Mot de passe}}">
                        <span class="input-group-btn">
                            <a class="btn btn-default form-control bt_showPass roundedRight" title="{{Afficher ou masquer le mot de passe}}"><i class="fas fa-eye"></i></a>
                        </span>
                    </div>
                    <p class="help-block"><i class="fas fa-lock"></i> {{Chiffré par le core Jeedom, jamais transmis au démon ni écrit dans les logs.}}</p>
                </div>
                <div class="localhomeconnect-profile-actions">
                    <button type="button" class="btn btn-success" id="bt_localhomeconnect_download_profiles"><i class="fas fa-cloud-download-alt"></i> {{Récupérer automatiquement}}</button>
                </div>
            </article>

            <article class="localhomeconnect-profile-tile localhomeconnect-profile-tile-browser">
                <header>
                    <span class="localhomeconnect-profile-icon"><i class="fas fa-window-restore"></i></span>
                    <div>
                        <span class="label label-warning">{{Avec navigateur}}</span>
                        <h3>{{Connexion dans une nouvelle page}}</h3>
                        <p>{{Le navigateur prend en charge JavaScript, SingleKey et hCaptcha.}}</p>
                    </div>
                </header>
                <ol class="localhomeconnect-profile-steps">
                    <li>{{Ouvrez SingleKey dans la nouvelle page et connectez-vous.}}</li>
                    <li>{{Sur « Redirection… », ouvrez la Console puis cliquez manuellement sur CONTINUER.}}</li>
                    <li>{{Copiez l’erreur redirect_target dans la fenêtre Jeedom pour terminer la récupération.}}</li>
                </ol>
                <div class="alert alert-warning localhomeconnect-tile-notice">
                    <i class="fas fa-info-circle"></i>
                    {{Utilisez cette méthode si la récupération automatique rencontre hCaptcha. Aucun mot de passe n’est transmis à Jeedom pendant cette connexion.}}
                </div>
                <div class="localhomeconnect-profile-actions">
                    <button type="button" class="btn btn-warning" id="bt_localhomeconnect_browser_authorization"><i class="fas fa-external-link-alt"></i> {{Ouvrir la connexion SingleKey}}</button>
                </div>
            </article>

            <article class="localhomeconnect-profile-tile localhomeconnect-profile-tile-manual">
                <header>
                    <span class="localhomeconnect-profile-icon"><i class="fas fa-file-archive"></i></span>
                    <div>
                        <span class="label label-info">{{Import manuel}}</span>
                        <h3>{{Home Connect Profile Downloader}}</h3>
                        <p>{{Générez le ZIP en dehors de Jeedom, puis importez-le sans le modifier.}}</p>
                    </div>
                </header>
                <ol class="localhomeconnect-profile-steps">
                    <li>{{Ouvrez l’outil et choisissez votre région.}}</li>
                    <li>{{Connectez-vous, sélectionnez la cible openHAB et téléchargez le ZIP.}}</li>
                    <li>{{Sélectionnez cette archive ci-dessous puis importez-la.}}</li>
                </ol>
                <a class="btn btn-default localhomeconnect-profile-downloader" href="https://github.com/bruestel/homeconnect-profile-downloader" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt"></i> {{Ouvrir Home Connect Profile Downloader}}</a>
                <div class="input-group localhomeconnect-profile-upload">
                    <input type="file" class="form-control" id="in_localhomeconnect_profile" accept=".zip,application/zip">
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-info" id="bt_localhomeconnect_import"><i class="fas fa-upload"></i> {{Importer le ZIP}}</button>
                    </span>
                </div>
            </article>
        </div>

        <section class="localhomeconnect-installed-profiles">
            <header>
                <span class="localhomeconnect-profile-icon"><i class="fas fa-shield-alt"></i></span>
                <div>
                    <h3>{{Profils installés}}</h3>
                    <p>{{Profils et clés actuellement disponibles pour la communication locale.}}</p>
                </div>
                <span class="badge" id="localhomeconnect-profile-count">…</span>
            </header>
            <div class="table-responsive">
                <table class="table table-condensed table-hover" id="table_localhomeconnect_profiles">
                    <thead><tr><th>{{Nom}}</th><th>{{Type}}</th><th>{{Modèle}}</th><th>{{Transport}}</th><th>{{Importé le}}</th><th class="text-center">{{Actions}}</th></tr></thead>
                    <tbody><tr><td colspan="6" class="text-center"><i class="fas fa-sync fa-spin"></i> {{Chargement}}</td></tr></tbody>
                </table>
            </div>
        </section>

        <div class="localhomeconnect-config-grid">
            <section class="localhomeconnect-config-card">
                <header>
                    <span class="localhomeconnect-config-card-icon"><i class="fas fa-network-wired"></i></span>
                    <div><h3>{{Communication locale}}</h3><p>{{Réglages de connexion et de découverte des appareils.}}</p></div>
                </header>
                <div class="localhomeconnect-config-card-body">
                    <div class="form-group">
                        <label class="col-sm-6 control-label" for="localhomeconnect_app_name">{{Nom de l’application locale}}</label>
                        <div class="col-sm-6"><input id="localhomeconnect_app_name" type="text" class="configKey form-control" data-l1key="app_name" placeholder="Jeedom LocalHomeConnect"></div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-6 control-label" for="localhomeconnect_daemon_port">{{Port interne du démon}}</label>
                        <div class="col-sm-6"><input id="localhomeconnect_daemon_port" type="number" min="1024" max="65535" class="configKey form-control" data-l1key="daemon_port"></div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-6 control-label" for="localhomeconnect_discovery_timeout">{{Durée de la découverte mDNS}}</label>
                        <div class="col-sm-6"><div class="input-group"><input id="localhomeconnect_discovery_timeout" type="number" min="2" max="30" class="configKey form-control" data-l1key="discovery_timeout"><span class="input-group-addon">s</span></div></div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-6 control-label" for="localhomeconnect_reconnect_interval">{{Nouvelle tentative après déconnexion}}</label>
                        <div class="col-sm-6"><div class="input-group"><input id="localhomeconnect_reconnect_interval" type="number" min="5" max="300" class="configKey form-control" data-l1key="reconnect_interval"><span class="input-group-addon">s</span></div></div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-6 control-label" for="localhomeconnect_watchdog_interval">{{Surveillance d’une connexion inactive}}</label>
                        <div class="col-sm-6"><div class="input-group"><input id="localhomeconnect_watchdog_interval" type="number" min="60" max="3600" class="configKey form-control" data-l1key="watchdog_interval"><span class="input-group-addon">s</span></div></div>
                    </div>
                </div>
            </section>
            <section class="localhomeconnect-config-card">
                <header>
                    <span class="localhomeconnect-config-card-icon"><i class="fas fa-shield-alt"></i></span>
                    <div><h3>{{Diagnostic et sécurité}}</h3><p>{{Journaux de communication et vérification des retours vers Jeedom.}}</p></div>
                </header>
                <div class="localhomeconnect-config-card-body">
                    <div class="form-group">
                        <label class="col-sm-6 control-label">{{Journal protocolaire détaillé}}</label>
                        <div class="col-sm-6">
                            <label class="checkbox-inline"><input type="checkbox" class="configKey" data-l1key="debug_protocol"> {{Activer}}</label>
                            <p class="help-block">{{Ces traces détaillées sont écrites uniquement lorsque le niveau de log Jeedom du plugin est réglé sur Debug.}}</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-6 control-label">{{Vérifier le certificat HTTPS de Jeedom}}</label>
                        <div class="col-sm-6">
                            <label class="checkbox-inline"><input type="checkbox" class="configKey" data-l1key="callback_verify_tls"> {{Activer}}</label>
                            <p class="help-block">{{Ne désactivez cette vérification que si l’adresse interne de Jeedom utilise un certificat autosigné et que les retours du démon échouent.}}</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</form>

<?php include_file('desktop', 'configuration', 'js', 'localhomeconnect'); ?>
