<?php

if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
}
$plugin = plugin::byId('localhomeconnect');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
$daemon = localhomeconnect::deamon_info();
$daemonAvailable = (string) ($daemon['state'] ?? 'nok') === 'ok';
$runtimeByHaId = array();
foreach ((array) ($daemon['devices'] ?? array()) as $runtime) {
    if (is_array($runtime) && isset($runtime['haId'])) {
        $runtimeByHaId[(string) $runtime['haId']] = $runtime;
    }
}
?>
<link rel="stylesheet" href="core/php/getResource.php?file=/plugins/localhomeconnect/desktop/css/localhomeconnect.css">

<div class="row row-overflow" id="div_localhomeconnect">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf"><i class="fas fa-wrench"></i><br><span>{{Configuration}}</span></div>
            <div class="cursor logoSecondary" id="bt_healthLocalHomeConnect"><i class="fas fa-medkit"></i><br><span>{{Santé}}</span></div>
            <div class="cursor logoPrimary" id="bt_discoverLocalHomeConnect"><i class="fas fa-broadcast-tower"></i><br><span>{{Découvrir}}</span></div>
        </div>

        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            {{Importez d’abord le ZIP des profils depuis la configuration du plugin, puis utilisez Découvrir pour retrouver les adresses locales par mDNS.}}
        </div>

        <legend><i class="fas fa-plug"></i> {{Mes appareils}}</legend>
        <div class="input-group localhomeconnect-search">
            <input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">
            <span class="input-group-btn">
                <button type="button" id="bt_resetSearch" class="btn btn-default" title="{{Réinitialiser}}"><i class="fas fa-times"></i></button>
                <button type="button" id="bt_pluginDisplayAsTable" class="btn btn-default roundedRight hidden" data-coreSupport="1" data-state="0" title="{{Afficher en tableau}}"><i class="fas fa-grip-lines"></i></button>
            </span>
        </div>
        <div class="eqLogicThumbnailContainer">
            <?php foreach ($eqLogics as $eqLogic) {
                $enabled = (bool) $eqLogic->getIsEnable();
                $runtime = $runtimeByHaId[(string) $eqLogic->getConfiguration('ha_id', '')] ?? null;
                $online = $enabled && $daemonAvailable && is_array($runtime) && !empty($runtime['connected']);
                $statusLabel = !$enabled
                    ? __('Désactivé', __FILE__)
                    : ($online ? __('Prêt', __FILE__) : __('Hors ligne', __FILE__));
                $statusClass = !$enabled ? 'is-disabled' : ($online ? 'is-online' : 'is-offline');
                $statusIcon = !$enabled ? 'fa-pause' : ($online ? 'fa-heartbeat' : 'fa-unlink');
                $typeLabel = $eqLogic->getDeviceTypeLabel();
                $model = trim((string) $eqLogic->getConfiguration('model', ''));
                $host = trim((string) $eqLogic->getConfiguration('host', ''));
                $transport = trim((string) $eqLogic->getConfiguration('connection_type', ''));
                $parentObject = $eqLogic->getObject();
                $object = is_object($parentObject) ? $parentObject->getName() : '';
                $title = __('Nom', __FILE__) . ' : ' . $eqLogic->getName() . '<br>'
                    . __('Type', __FILE__) . ' : ' . $typeLabel . '<br>'
                    . __('Modèle', __FILE__) . ' : ' . ($model !== '' ? $model : '-') . '<br>'
                    . __('Adresse', __FILE__) . ' : ' . ($host !== '' ? $host : '-') . '<br>'
                    . __('Transport', __FILE__) . ' : ' . ($transport !== '' ? $transport : '-') . '<br>'
                    . __('État', __FILE__) . ' : ' . $statusLabel;
                ?>
                <div class="eqLogicDisplayCard cursor localhomeconnect-equipment-card <?php echo $enabled ? '' : 'disableCard'; ?>" data-eqLogic_id="<?php echo (int) $eqLogic->getId(); ?>" title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="localhomeconnect-card-status <?php echo $statusClass; ?>" title="<?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="fas <?php echo $statusIcon; ?>"></i>
                    </span>
                    <img class="localhomeconnect-card-logo" src="<?php echo htmlspecialchars($eqLogic->getPathLogo(), ENT_QUOTES, 'UTF-8'); ?>" onerror="this.src='plugins/localhomeconnect/plugin_info/localhomeconnect_icon.svg'" alt="">
                    <span class="name"><?php echo htmlspecialchars($eqLogic->getName(), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="localhomeconnect-card-object"><?php echo htmlspecialchars($object, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="localhomeconnect-card-details">
                        <span class="label label-info"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($host !== '') { ?><small><i class="fas fa-network-wired"></i> <?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></small><?php } ?>
                    </span>
                    <span class="hidden hiddenAsCard displayTableRight localhomeconnect-table-details">
                        <span><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($model !== '') { ?><span><?php echo htmlspecialchars($model, ENT_QUOTES, 'UTF-8'); ?></span><?php } ?>
                        <?php if (!$enabled) { ?>
                            <i class="fas fa-pause" title="{{Désactivé}}"></i>
                        <?php } else {
                            echo $online
                                ? '<i class="fas fa-heartbeat text-success" title="{{Prêt}}"></i>'
                                : '<i class="fas fa-unlink text-danger" title="{{Hors ligne}}"></i>';
                        } ?>
                        <?php echo $eqLogic->getIsEnable() ? '<i class="far fa-check-square" title="{{Équipement activé}}"></i>' : '<i class="far fa-square" title="{{Équipement non activé}}"></i>'; ?>
                        <?php echo $eqLogic->getIsVisible() ? '<i class="fas fa-eye" title="{{Équipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Équipement non visible}}"></i>'; ?>
                        <?php echo (int) $eqLogic->getDisplay('widgetTmpl', 1) === 1 ? '<i class="fas jeedomapp-design-jeedom" title="{{Widget LocalHomeConnect}}"></i>' : '<i class="fas jeedomapp-home-jeedom" title="{{Widget Jeedom}}"></i>'; ?>
                    </span>
                </div>
            <?php } ?>
        </div>
        <?php if (count($eqLogics) === 0) { ?>
            <div class="alert alert-warning text-center">{{Aucun profil importé. Ouvrez la configuration du plugin pour commencer.}}</div>
        <?php } ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display:none;">
        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <button type="button" class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></button>
                <button type="button" class="btn btn-sm btn-warning" id="bt_refreshLocalHomeConnect"><i class="fas fa-sync"></i> {{Actualiser}}</button>
                <button type="button" class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</button>
                <button type="button" class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</button>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Équipement}}</a></li>
            <li role="presentation"><a href="#commandtab" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
        </ul>

        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal"><fieldset>
                    <div class="col-lg-6">
                        <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
                        <div class="form-group"><label class="col-sm-4 control-label">{{Nom}}</label><div class="col-sm-7"><input type="hidden" class="eqLogicAttr" data-l1key="id"><input type="text" class="eqLogicAttr form-control" data-l1key="name"></div></div>
                        <div class="form-group"><label class="col-sm-4 control-label">{{Objet parent}}</label><div class="col-sm-7"><select class="eqLogicAttr form-control" data-l1key="object_id"><option value="">{{Aucun}}</option>
                            <?php foreach (jeeObject::buildTree(null, false) as $object) {
                                echo '<option value="' . (int) $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', (int) $object->getConfiguration('parentNumber')) . htmlspecialchars($object->getName(), ENT_QUOTES, 'UTF-8') . '</option>';
                            } ?>
                        </select></div></div>
                        <div class="form-group"><label class="col-sm-4 control-label">{{Catégorie}}</label><div class="col-sm-8">
                            <?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                echo '<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($value['name'], ENT_QUOTES, 'UTF-8') . '</label>';
                            } ?>
                        </div></div>
                        <div class="form-group"><label class="col-sm-4 control-label">{{Options}}</label><div class="col-sm-7"><label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable">{{Activer}}</label><label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible">{{Visible}}</label></div></div>
                        <div class="form-group"><label class="col-sm-4 control-label">{{Widget}}</label><div class="col-sm-7"><select class="eqLogicAttr form-control" data-l1key="display" data-l2key="widgetTmpl"><option value="0">{{Widget du core Jeedom}}</option><option value="1">{{Widget LocalHomeConnect}}</option></select></div></div>
                    </div>
                    <div class="col-lg-6">
                        <legend><i class="fas fa-network-wired"></i> {{Informations locales}}</legend>
                        <div class="form-group"><label class="col-sm-4 control-label">{{Adresse IP ou nom réseau}}</label><div class="col-sm-8"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="host"></div></div>
                        <?php
                        $fields = array(
                            'ha_id' => __('Identifiant Home Connect', __FILE__),
                            'brand' => __('Marque', __FILE__),
                            'device_type' => __('Type', __FILE__),
                            'model' => __('Modèle', __FILE__),
                            'serial' => __('Numéro de série', __FILE__),
                            'connection_type' => __('Transport', __FILE__),
                            'software_version' => __('Version logicielle', __FILE__),
                            'last_error' => __('Dernière erreur', __FILE__),
                        );
                        foreach ($fields as $key => $label) { ?>
                            <div class="form-group"><label class="col-sm-4 control-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></label><div class="col-sm-8"><span class="eqLogicAttr localhomeconnect-value" data-l1key="configuration" data-l2key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"></span></div></div>
                        <?php } ?>
                        <div class="form-group"><label class="col-sm-4 control-label">{{Dernière communication}}</label><div class="col-sm-8"><span class="eqLogicAttr localhomeconnect-value" data-l1key="status" data-l2key="lastCommunication"></span></div></div>
                        <div class="form-group"><div class="col-sm-8 col-sm-offset-4"><button type="button" class="btn btn-default" id="bt_testCommunicationLocalHomeConnect"><i class="fas fa-satellite-dish"></i> {{Tester la communication}}</button></div></div>
                    </div>
                </fieldset></form>
            </div>
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <div class="alert alert-info">{{Les commandes sont créées à partir du profil XML et des droits réellement annoncés par l’appareil. Les fonctions sensibles de réinitialisation ou de mise à jour sont volontairement bloquées.}}</div>
                <div class="table-responsive"><table id="table_cmd" class="table table-bordered table-condensed"><thead><tr><th class="hidden-xs">ID</th><th>{{Nom}}</th><th>{{Type}}</th><th>{{Options}}</th><th>{{État}}</th><th>{{Actions}}</th></tr></thead><tbody></tbody></table></div>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'localhomeconnect', 'js', 'localhomeconnect'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
