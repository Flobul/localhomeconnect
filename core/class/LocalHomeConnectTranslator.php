<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify it under the
 * terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or any later version.
 */

require_once __DIR__ . '/LocalHomeConnectCapabilities.php';

/**
 * Centralise les traductions et les compléments de métadonnées Home Connect.
 */
final class LocalHomeConnectTranslator
{
    /** @var LocalHomeConnectCapabilities|null Dictionnaire chargé à la demande. */
    private static $dictionary = null;

    /**
     * Traduit un type d'appareil Home Connect.
     *
     * @param string $type Type technique.
     * @return string
     */
    public static function applianceType($type)
    {
        $types = self::dictionary()->appliancesList;
        return isset($types[$type]) ? $types[$type] : self::humanize($type);
    }

    /**
     * Traduit le nom d'une fonction, d'un réglage ou d'un événement.
     *
     * @param string $feature Clé Home Connect complète.
     * @return string
     */
    public static function feature($feature)
    {
        if (strpos($feature, '.Program.') !== false) {
            return self::program($feature);
        }
        $zone = self::hobZoneLabel($feature);
        if ($zone !== '' && preg_match('/Cooking\.Hob\.StatusList\.Zone\.\d+$/i', (string) $feature)) {
            return self::appendZoneLabel(__('Informations du foyer', __FILE__), $zone);
        }
        if (preg_match('/\.Setting\.Light\.Cavity\.\d+\.Power$/i', (string) $feature)) {
            return __('Éclairage de la cavité', __FILE__);
        }
        $short = self::shortName($feature);
        $labels = self::fallbackLabels();
        if (isset($labels[$short])) {
            return self::appendZoneLabel($labels[$short], $zone);
        }
        $capability = self::capability($feature);
        if (isset($capability['name']) && trim((string) $capability['name']) !== '') {
            return self::appendZoneLabel((string) $capability['name'], $zone);
        }
        return self::appendZoneLabel(self::humanize($short), $zone);
    }

    /**
     * Traduit une valeur d'énumération en utilisant d'abord le dictionnaire.
     *
     * @param string $feature Clé de la fonction.
     * @param mixed $value Valeur brute ou libellé issu du profil local.
     * @return mixed
     */
    public static function value($feature, $value)
    {
        if (preg_match('/(?:ActiveProgram|SelectedProgram)$/i', (string) $feature) && (string) $value === '0') {
            return __('Aucun programme', __FILE__);
        }
        if (!is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if (strpos($trimmed, '.Program.') !== false) {
            return self::program($trimmed);
        }
        if (preg_match('/(?:PowerLevel|PowerMoveModeDefaultValue)/i', (string) $feature)
            && preg_match('/^(\d{2})$/', $trimmed, $matches)) {
            $level = ((int) $matches[1]) / 10;
            return rtrim(rtrim(number_format($level, 1, ',', ''), '0'), ',');
        }
        if (preg_match('/^(\d+)dC$/i', $trimmed, $matches)) {
            return $matches[1] . ' °C';
        }
        if (preg_match('/^(\d+)dF$/i', $trimmed, $matches)) {
            return $matches[1] . ' °F';
        }
        if (preg_match('/^(\d+)W$/i', $trimmed, $matches)) {
            return number_format((int) $matches[1], 0, ',', ' ') . ' W';
        }
        if (preg_match('/^(\d+)seconds?$/i', $trimmed, $matches)) {
            return sprintf(__('%s secondes', __FILE__), $matches[1]);
        }
        if (preg_match('/^(?:Level|BoostLevel)0?(\d+)$/i', $trimmed, $matches)) {
            return (stripos($trimmed, 'Boost') === 0 ? __('Intensif', __FILE__) : __('Niveau', __FILE__)) . ' ' . $matches[1];
        }
        $capability = self::capability($feature);
        foreach ((array) ($capability['enum'] ?? array()) as $enumKey => $definition) {
            if ($trimmed === (string) $enumKey || self::normalizedToken($trimmed) === self::normalizedToken($enumKey)) {
                return isset($definition['name']) ? $definition['name'] : $trimmed;
            }
        }
        $short = self::shortName($trimmed);
        $states = self::fallbackValues();
        $normalized = self::normalizedToken($short);
        foreach ($states as $state => $translation) {
            if (self::normalizedToken($state) === $normalized) {
                return $translation;
            }
        }
        if (strpos($feature, '.Program.') !== false) {
            return self::program($feature);
        }
        return $value;
    }

    /**
     * Traduit un programme Home Connect.
     *
     * @param string $feature Clé complète du programme.
     * @return string
     */
    public static function program($feature)
    {
        $capability = self::capability($feature);
        if (isset($capability['name']) && trim((string) $capability['name']) !== '') {
            return (string) $capability['name'];
        }
        $short = preg_replace('/^.*\.Program\./', '', (string) $feature);
        if (preg_match('/^Favorite\.(.+)$/i', $short, $matches)) {
            return __('Favori', __FILE__) . ' ' . $matches[1];
        }
        return self::humanize($short);
    }

    /**
     * Retourne le type, l'unité et les bornes connues pour une fonction.
     *
     * Les informations du profil de l'appareil restent prioritaires. Ces
     * valeurs ne servent qu'à compléter les profils locaux incomplets.
     *
     * @param string $feature Clé Home Connect.
     * @return array<string,mixed>
     */
    public static function metadata($feature)
    {
        $capability = self::capability($feature);
        $metadata = array();
        if (isset($capability['type'])) {
            $metadata['type'] = (string) $capability['type'];
        }
        if (isset($capability['unit'])) {
            $metadata['unit'] = (string) $capability['unit'];
        }
        $constraints = is_array($capability['constraints'] ?? null) ? $capability['constraints'] : array();
        foreach (array('min', 'max') as $key) {
            if (isset($constraints[$key]) && is_numeric($constraints[$key])) {
                $metadata[$key] = (float) $constraints[$key];
            }
        }
        if (isset($constraints['stepsize']) && is_numeric($constraints['stepsize'])) {
            $metadata['step'] = (float) $constraints['stepsize'];
        }

        $short = self::shortName($feature);
        $types = array(
            'AllowBackendConnection' => 'Boolean',
            'AllowConsumerInsights' => 'Boolean',
            'BackendConnected' => 'Boolean',
            'ButtonTones' => 'Boolean',
            'CustomerServiceConnectionAllowed' => 'Boolean',
            'InteriorIllumination' => 'Boolean',
            'OvenLightDuringOperation' => 'Boolean',
            'SynchronizeWithTimeServer' => 'Boolean',
            'WaterTankUnplugged' => 'Boolean',
            'ApplianceTime' => 'String',
            'ActiveProgram' => 'String',
            'SelectedProgram' => 'String',
            'Position' => 'String',
            'Weight' => 'Int',
        );
        if (!isset($metadata['type']) && isset($types[$short])) {
            $metadata['type'] = $types[$short];
        }
        if (!isset($metadata['type']) && $short === 'Power' && preg_match('/\.(?:Status|Setting)(?:\.[^.]+)*\.Power$/i', $feature)) {
            $metadata['type'] = 'Boolean';
            unset($metadata['unit']);
        }
        if (in_array($short, array(
            'StartInRelative', 'FinishInRelative', 'Duration', 'AlarmClock',
            'ElapsedProgramTime', 'RemainingProgramTime', 'EstimatedTotalProgramTime',
            'StopWatchTime', 'SwitchOffTimer'
        ), true)) {
            $metadata += array('type' => 'Int', 'unit' => 'seconds', 'min' => 0.0, 'max' => 86400.0, 'step' => 60.0);
        }
        if ($short === 'AutomaticTimer' && strpos((string) $feature, 'Cooking.Hob.') === 0) {
            $metadata += array('type' => 'Int', 'unit' => 'minutes', 'min' => 0.0, 'max' => 99.0, 'step' => 1.0);
        }
        if ($short === 'WiFiSignalStrength') {
            $metadata += array('type' => 'Int', 'unit' => 'dBm');
        }
        if (in_array($short, array('LengthX', 'LengthY'), true) && strpos((string) $feature, 'Cooking.Hob.Status') === 0) {
            $metadata += array('type' => 'Double', 'unit' => 'centimeter');
        }
        if ($short === 'Weight' && !isset($metadata['unit'])) {
            $metadata['unit'] = 'g';
        }
        return $metadata;
    }

    /**
     * Produit un libellé distinct pour une action liée à une information.
     *
     * @param string $feature Clé Home Connect.
     * @param string $label Libellé de l'information.
     * @param string $subType Sous-type Jeedom.
     * @param mixed $fixedValue Valeur imposée, le cas échéant.
     * @return string
     */
    public static function actionLabel($feature, $label, $subType, $fixedValue = null)
    {
        if (strpos($feature, '.Command.') !== false) {
            return $label;
        }
        if ($fixedValue !== null && $subType === 'other') {
            return $label . ' - ' . ((bool) $fixedValue ? __('Activer', __FILE__) : __('Désactiver', __FILE__));
        }
        return $label . ' - ' . __('Régler', __FILE__);
    }

    /**
     * Retourne la définition exacte d'une capacité.
     *
     * @param string $feature Clé Home Connect.
     * @return array<string,mixed>
     */
    private static function capability($feature)
    {
        $capabilities = self::dictionary()->appliancesCapabilities;
        return isset($capabilities[$feature]) && is_array($capabilities[$feature])
            ? $capabilities[$feature]
            : array();
    }

    /**
     * Charge une seule fois le dictionnaire volumineux.
     *
     * @return LocalHomeConnectCapabilities
     */
    private static function dictionary()
    {
        if (!is_object(self::$dictionary)) {
            self::$dictionary = new LocalHomeConnectCapabilities();
        }
        return self::$dictionary;
    }

    /**
     * Extrait la dernière partie d'une clé Home Connect.
     *
     * @param string $value Clé complète ou valeur d'énumération.
     * @return string
     */
    private static function shortName($value)
    {
        return (string) preg_replace('/^.*\./', '', trim((string) $value));
    }

    /**
     * Transforme un identifiant technique en libellé de secours.
     *
     * @param string $value Identifiant.
     * @return string
     */
    private static function humanize($value)
    {
        $readable = preg_replace('/([a-zà-ÿ])([A-Z])/', '$1 $2', str_replace(array('_', '-', '.'), ' ', (string) $value));
        $readable = preg_replace('/([A-Za-zÀ-ÿ])([0-9])/', '$1 $2', $readable);
        return trim((string) preg_replace('/\s+/', ' ', $readable));
    }

    /**
     * Retourne le libellé humain d'une zone de table de cuisson.
     *
     * Les zones 120 et 340 représentent les surfaces combinées gauche et
     * droite du profil Home Connect, les quatre autres sont les foyers.
     *
     * @param string $feature Fonction Home Connect.
     * @return string
     */
    private static function hobZoneLabel($feature)
    {
        if (!preg_match('/Cooking\.Hob\.(?:Status|StatusList)\.Zone\.(\d+)/i', (string) $feature, $matches)) {
            return '';
        }
        $zones = array(
            '100' => __('Avant gauche', __FILE__),
            '120' => __('Zone flexible gauche', __FILE__),
            '200' => __('Arrière gauche', __FILE__),
            '300' => __('Arrière droite', __FILE__),
            '340' => __('Zone flexible droite', __FILE__),
            '400' => __('Avant droite', __FILE__),
        );
        return isset($zones[$matches[1]]) ? $zones[$matches[1]] : sprintf(__('Zone %s', __FILE__), $matches[1]);
    }

    /**
     * Ajoute la position du foyer à un libellé sans exposer son UID.
     *
     * @param string $label Libellé de la fonction.
     * @param string $zone Zone traduite.
     * @return string
     */
    private static function appendZoneLabel($label, $zone)
    {
        return $zone === '' ? $label : $label . ' — ' . $zone;
    }

    /**
     * Compare les états avant et après leur humanisation par le démon.
     *
     * @param string $value État ou clé d'énumération.
     * @return string
     */
    private static function normalizedToken($value)
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', self::shortName($value)));
    }

    /**
     * Retourne les traductions absentes de l'ancien dictionnaire cloud.
     *
     * @return array<string,string>
     */
    private static function fallbackLabels()
    {
        return array(
            'ActiveProgram' => __('Programme actif', __FILE__),
            'SelectedProgram' => __('Programme sélectionné', __FILE__),
            'OperationState' => __('État', __FILE__),
            'DoorState' => __('Porte', __FILE__),
            'PowerState' => __('Alimentation', __FILE__),
            'Power' => __('Alimentation', __FILE__),
            'RemoteControlActive' => __('Contrôle à distance actif', __FILE__),
            'RemoteControlStartAllowed' => __('Démarrage à distance autorisé', __FILE__),
            'RemoteControlLevel' => __('Niveau de contrôle à distance', __FILE__),
            'LocalControlActive' => __('Commande locale active', __FILE__),
            'ProgramProgress' => __('Progression du programme', __FILE__),
            'RemainingProgramTime' => __('Temps de programme restant', __FILE__),
            'ElapsedProgramTime' => __('Temps de programme écoulé', __FILE__),
            'EstimatedTotalProgramTime' => __('Durée totale estimée', __FILE__),
            'StartInRelative' => __('Départ différé', __FILE__),
            'FinishInRelative' => __('Fin différée', __FILE__),
            'Duration' => __('Durée', __FILE__),
            'ProgramPhase' => __('Phase du programme', __FILE__),
            'ProcessPhase' => __('Phase en cours', __FILE__),
            'CurrentTemperature' => __('Température actuelle', __FILE__),
            'CurrentCavityTemperature' => __('Température actuelle', __FILE__),
            'CavitySelector' => __('Cavité du four', __FILE__),
            'CavityHeatup' => __('Préchauffage de la cavité', __FILE__),
            'ProgramAborted' => __('Programme interrompu', __FILE__),
            'ProgramFinished' => __('Programme terminé', __FILE__),
            'TemperatureTooHigh' => __('Température trop élevée', __FILE__),
            'CavityTemperatureTooHigh' => __('Température du four trop élevée', __FILE__),
            'FastPreheatFinished' => __('Préchauffage rapide terminé', __FILE__),
            'RegularPreheatFinished' => __('Préchauffage terminé', __FILE__),
            'OvenLightDuringOperation' => __('Éclairage pendant la cuisson', __FILE__),
            'OvenLockWhileCoolingDown' => __('Four verrouillé pendant le refroidissement', __FILE__),
            'CoolingFanRunOnTime' => __('Durée de ventilation après cuisson', __FILE__),
            'SubsequentCookingRequest' => __('Poursuivre la cuisson', __FILE__),
            'Weight' => __('Poids', __FILE__),
            'AllowBackendConnection' => __('Autoriser la connexion au service distant', __FILE__),
            'BackendConnected' => __('Connexion au service distant', __FILE__),
            'AllowConsumerInsights' => __('Partager les données de consommation', __FILE__),
            'CustomerServiceConnectionAllowed' => __('Accès du service client autorisé', __FILE__),
            'CustomerServiceRequest' => __('Demande d’assistance', __FILE__),
            'ApplianceModuleError' => __('Erreur du module de l’appareil', __FILE__),
            'ApplianceTime' => __('Heure de l’appareil', __FILE__),
            'ConnectLocalWiFi' => __('Connexion Wi-Fi locale', __FILE__),
            'SynchronizeWithTimeServer' => __('Synchronisation automatique de l’heure', __FILE__),
            'ClockDisplay' => __('Affichage de l’horloge', __FILE__),
            'ButtonTones' => __('Sons des touches', __FILE__),
            'SignalDuration' => __('Durée du signal', __FILE__),
            'DisplayBrightness' => __('Luminosité de l’écran', __FILE__),
            'ConfigureChildLock' => __('Configurer la sécurité enfants', __FILE__),
            'ChildLock' => __('Sécurité enfants', __FILE__),
            'SabbathMode' => __('Mode sabbat', __FILE__),
            'SwitchOnDelay' => __('Délai de mise en marche', __FILE__),
            'NetworkInterface' => __('Interface réseau', __FILE__),
            'LastRemoteStartRelease' => __('Dernière autorisation de démarrage à distance', __FILE__),
            'OperatingTimeLimitReached' => __('Durée maximale de fonctionnement atteinte', __FILE__),
            'SoftwareDownloadAvailable' => __('Téléchargement logiciel disponible', __FILE__),
            'SoftwareUpdateAvailable' => __('Mise à jour logicielle disponible', __FILE__),
            'SoftwareUpdateSuccessful' => __('Mise à jour logicielle réussie', __FILE__),
            'UserInteractionRequired' => __('Action de l’utilisateur requise', __FILE__),
            'WaterTankUnplugged' => __('Réservoir d’eau absent', __FILE__),
            'InteriorIllumination' => __('Éclairage intérieur', __FILE__),
            'AlarmClockElapsed' => __('Minuteur écoulé', __FILE__),
            'AlarmClock' => __('Minuteur', __FILE__),
            'StopWatchState' => __('État du chronomètre', __FILE__),
            'StopWatchTime' => __('Temps du chronomètre', __FILE__),
            'WiFiSignalStrength' => __('Signal Wi-Fi', __FILE__),
            'WipeProtectionActive' => __('Protection de nettoyage active', __FILE__),
            'AirCirculationMode' => __('Mode de circulation de l’air', __FILE__),
            'AirQualitySensorSensitivity' => __('Sensibilité du capteur de qualité de l’air', __FILE__),
            'AutomaticKeyLock' => __('Verrouillage automatique', __FILE__),
            'AutomaticTimer' => __('Minuteur automatique', __FILE__),
            'AutomaticZoneSelection' => __('Sélection automatique des foyers', __FILE__),
            'BuzzerBeepLevel' => __('Signaux sonores', __FILE__),
            'EndTimerSignalduration' => __('Durée du signal de fin', __FILE__),
            'EnergyConsumptionIndication' => __('Affichage de la consommation d’énergie', __FILE__),
            'FryingSensorLevel' => __('Niveau du capteur de friture', __FILE__),
            'CookingSensorLevel' => __('Température de cuisson', __FILE__),
            'PowerLevel' => __('Niveau de puissance', __FILE__),
            'PowerManagement' => __('Limitation de puissance', __FILE__),
            'PowerMoveModeDefaultValueFrontLeft' => __('Puissance par défaut — avant gauche', __FILE__),
            'PowerMoveModeDefaultValueFrontRight' => __('Puissance par défaut — avant droite', __FILE__),
            'PowerMoveModeDefaultValueMiddleLeft' => __('Puissance par défaut — milieu gauche', __FILE__),
            'PowerMoveModeDefaultValueMiddleRight' => __('Puissance par défaut — milieu droite', __FILE__),
            'PowerMoveModeDefaultValueRearLeft' => __('Puissance par défaut — arrière gauche', __FILE__),
            'PowerMoveModeDefaultValueRearRight' => __('Puissance par défaut — arrière droite', __FILE__),
            'Ventilation' => __('Niveau de ventilation', __FILE__),
            'VentilationAfterRun' => __('Post-ventilation', __FILE__),
            'VentilationAutomaticStart' => __('Démarrage automatique de la ventilation', __FILE__),
            'ZoneSelectionTime' => __('Durée de sélection du foyer', __FILE__),
            'ZoneSelector' => __('Foyer', __FILE__),
            'LengthX' => __('Largeur', __FILE__),
            'LengthY' => __('Profondeur', __FILE__),
            'Position' => __('Position', __FILE__),
            'Shape' => __('Forme', __FILE__),
            'State' => __('État du foyer', __FILE__),
            'SwitchOffTimer' => __('Minuteur d’arrêt', __FILE__),
            'CarbonFilterSaturation' => __('Saturation du filtre à charbon', __FILE__),
            'ApplianceOverheated' => __('Surchauffe de l’appareil', __FILE__),
            'ConfirmActionAtAppliance' => __('Confirmation requise sur l’appareil', __FILE__),
            'AcknowledgeEvent' => __('Acquitter l’événement', __FILE__),
            'AllowCustomerServiceConnectionLocalWiFi' => __('Autoriser le service client en Wi-Fi local', __FILE__),
            'AllowSoftwareDownload' => __('Autoriser le téléchargement logiciel', __FILE__),
            'AllowSoftwareUpdateLocalWiFi' => __('Autoriser la mise à jour en Wi-Fi local', __FILE__),
            'ApplyFactoryReset' => __('Rétablir les réglages d’usine', __FILE__),
            'ApplyNetworkReset' => __('Réinitialiser le réseau', __FILE__),
            'DeactivateRemoteControl' => __('Désactiver le contrôle à distance', __FILE__),
            'DeactivateWiFi' => __('Désactiver le Wi-Fi', __FILE__),
            'DisallowCustomerServiceConnection' => __('Refuser l’accès du service client', __FILE__),
            'RejectEvent' => __('Refuser l’événement', __FILE__),
            'StopWatchReset' => __('Réinitialiser le chronomètre', __FILE__),
            'StopWatchStartPauseResume' => __('Démarrer, mettre en pause ou reprendre le chronomètre', __FILE__),
            'CommandList' => __('Liste des commandes', __FILE__),
            'EventList' => __('Liste des événements', __FILE__),
            'OptionList' => __('Liste des options', __FILE__),
            'ProgramGroup' => __('Groupe de programmes', __FILE__),
            'ProtectionPort' => __('Port de protection', __FILE__),
            'SettingList' => __('Liste des réglages', __FILE__),
            'StatusList' => __('Liste des états', __FILE__),
            'SoftwareUpdateTransactionID' => __('Identifiant de la mise à jour logicielle', __FILE__),
            'CarbonFilterReset' => __('Réinitialiser le filtre à charbon', __FILE__),
            'CarbonFilterMaxSaturationNearlyReached' => __('Filtre à charbon bientôt saturé', __FILE__),
            'CarbonFilterMaxSaturationReached' => __('Filtre à charbon saturé', __FILE__),
            'PowerMoveModeValueFront' => __('Puissance de la zone avant', __FILE__),
            'PowerMoveModeValueMiddle' => __('Puissance de la zone centrale', __FILE__),
            'PowerMoveModeValueRear' => __('Puissance de la zone arrière', __FILE__),
        );
    }

    /**
     * Retourne les traductions des états communs à toutes les familles.
     *
     * @return array<string,string>
     */
    private static function fallbackValues()
    {
        return array(
            'Open' => __('Ouverte', __FILE__), 'Closed' => __('Fermée', __FILE__),
            'Ajar' => __('Entrouverte', __FILE__), 'Locked' => __('Verrouillée', __FILE__),
            'Unlocked' => __('Déverrouillée', __FILE__), 'On' => __('Allumé', __FILE__),
            'Off' => __('Éteint', __FILE__), 'Standby' => __('En veille', __FILE__),
            'MainsOff' => __('Hors tension', __FILE__), 'Ready' => __('Prêt', __FILE__),
            'Inactive' => __('Inactif', __FILE__), 'Run' => __('En cours', __FILE__),
            'Running' => __('En cours', __FILE__), 'Pause' => __('En pause', __FILE__),
            'DelayedStart' => __('Départ programmé', __FILE__), 'Finished' => __('Terminé', __FILE__),
            'Aborting' => __('Interruption en cours', __FILE__), 'Error' => __('Erreur', __FILE__),
            'ActionRequired' => __('Action requise', __FILE__), 'Present' => __('Présent', __FILE__),
            'Confirmed' => __('Confirmé', __FILE__),
            'NotPresent' => __('Absent', __FILE__), 'Detected' => __('Détecté', __FILE__),
            'NotDetected' => __('Non détecté', __FILE__), 'Enabled' => __('Activé', __FILE__),
            'Disabled' => __('Désactivé', __FILE__), 'Activated' => __('Activé', __FILE__),
            'Deactivated' => __('Désactivé', __FILE__), 'Allowed' => __('Autorisé', __FILE__),
            'NotAllowed' => __('Non autorisé', __FILE__), 'Available' => __('Disponible', __FILE__),
            'NotAvailable' => __('Indisponible', __FILE__), 'Empty' => __('Vide', __FILE__),
            'NearlyEmpty' => __('Presque vide', __FILE__), 'Full' => __('Plein', __FILE__),
            'Low' => __('Faible', __FILE__), 'Middle' => __('Moyen', __FILE__),
            'Medium' => __('Moyen', __FILE__), 'High' => __('Élevé', __FILE__),
            'Auto' => __('Automatique', __FILE__), 'Manual' => __('Manuel', __FILE__),
            'True' => __('Oui', __FILE__), 'False' => __('Non', __FILE__),
            'NoPhase' => __('Aucune phase', __FILE__), 'PreHeating' => __('Préchauffage', __FILE__),
            'Washing' => __('Lavage', __FILE__), 'Rinsing' => __('Rinçage', __FILE__),
            'Spinning' => __('Essorage', __FILE__), 'Drying' => __('Séchage', __FILE__),
            'Cooling' => __('Refroidissement', __FILE__), 'Cleaning' => __('Nettoyage', __FILE__),
            'Clock01' => __('Horloge 1', __FILE__), 'Clock02' => __('Horloge 2', __FILE__),
            'PermanentRemoteStart' => __('Démarrage à distance permanent', __FILE__),
            'Short' => __('Court', __FILE__), 'Long' => __('Long', __FILE__),
            'Paused' => __('En pause', __FILE__),
            'Automatic' => __('Automatique', __FILE__),
            'AutomaticMode' => __('Mode automatique', __FILE__),
            'ManualMode' => __('Mode manuel', __FILE__),
            'Recirculation' => __('Recyclage', __FILE__),
            'Extraction' => __('Évacuation extérieure', __FILE__),
            'IndicationOn' => __('Affichage activé', __FILE__),
            'IndicationOff' => __('Affichage désactivé', __FILE__),
            'KeyLockFunctionDeactivated' => __('Verrouillage automatique indisponible', __FILE__),
            'AllOff' => __('Tous les signaux désactivés', __FILE__),
            'AcknowledgeOff' => __('Signal de confirmation désactivé', __FILE__),
            'WarningMalOff' => __('Signaux d’avertissement désactivés', __FILE__),
            'AllActive' => __('Tous les signaux activés', __FILE__),
            'KeepWarm' => __('Maintien au chaud', __FILE__),
            'Boost1' => __('Intensif 1', __FILE__), 'Boost2' => __('Intensif 2', __FILE__),
            'AfterRun' => __('Post-ventilation', __FILE__),
            'Infinite' => __('Illimitée', __FILE__), 'Limited' => __('Limitée', __FILE__),
            'NotSelectable' => __('Non sélectionnable', __FILE__),
            'Active' => __('Actif', __FILE__), 'ResiduelHeat' => __('Chaleur résiduelle', __FILE__),
            'Round' => __('Ronde', __FILE__), 'Oval' => __('Ovale', __FILE__),
            'Rectangular' => __('Rectangulaire', __FILE__), 'Octangular' => __('Octogonale', __FILE__),
        );
    }
}
