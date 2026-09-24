<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify it under the
 * terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or any later version.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/LocalHomeConnectProfile.php';
require_once __DIR__ . '/LocalHomeConnectCloud.php';
require_once __DIR__ . '/LocalHomeConnectTranslator.php';
require_once __DIR__ . '/LocalHomeConnectActionValidator.php';
require_once __DIR__ . '/LocalHomeConnectStorage.php';

/**
 * Représente un appareil Home Connect piloté directement sur le réseau local.
 */
class localhomeconnect extends eqLogic
{
    public static $_pluginVersion = '0.1.2';
    public static $_widgetPossibility = array('custom' => true, 'custom::layout' => true);
    public static $_encryptConfigKey = array('homeconnect_password', 'daemon_token');

    /** @var LocalHomeConnectProfileStore|null Magasin de profils partagé durant la requête PHP. */
    private static $profileStoreInstance = null;

    /** @var int Profondeur du verrou réentrant de cycle de vie du démon. */
    private static $daemonLifecycleLockDepth = 0;

    /** @var resource|null Descripteur conservant le verrou du démon. */
    private static $daemonLifecycleLockHandle = null;

    /** @var bool Protection du stockage déjà vérifiée pendant cette requête. */
    private static $privateStorageChecked = false;

    /**
     * Retourne le répertoire privé du plugin en le créant si nécessaire.
     *
     * @return string
     */
    private static function dataPath()
    {
        $path = realpath(__DIR__ . '/../..') . '/data';
        if (is_link($path)) {
            throw new RuntimeException(__('Lien symbolique interdit sur le stockage privé', __FILE__));
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException(__('Création du répertoire de données impossible', __FILE__));
        }
        if (!self::$privateStorageChecked) {
            self::securePrivateStorage();
        }
        return $path;
    }

    /**
     * Répare aussi les données existantes après une copie ou une mise à jour.
     *
     * @return array{state:bool,message:string}
     */
    public static function securePrivateStorage()
    {
        $result = LocalHomeConnectStorage::repair(realpath(__DIR__ . '/../..') . '/data');
        self::$privateStorageChecked = true;
        if (!$result['state']) {
            log::add(__CLASS__, 'warning', __('Protection du stockage privé incomplète :', __FILE__) . ' ' . $result['message']);
        }
        return $result;
    }

    /**
     * Construit le magasin de profils Home Connect.
     *
     * @return LocalHomeConnectProfileStore
     */
    public static function profileStore()
    {
        if (!(self::$profileStoreInstance instanceof LocalHomeConnectProfileStore)) {
            self::$profileStoreInstance = new LocalHomeConnectProfileStore(
                self::dataPath() . '/profiles',
                function ($level, $message) {
                    log::add(__CLASS__, $level, $message);
                }
            );
        }
        return self::$profileStoreInstance;
    }

    /**
     * Importe une archive et crée ou actualise les équipements correspondants.
     *
     * @param string $archivePath Fichier ZIP temporaire.
     * @return array<int,array<string,mixed>>
     */
    public static function importProfiles($archivePath)
    {
        $profiles = self::profileStore()->importZip($archivePath);
        $result = array();
        foreach ($profiles as $profile) {
            $result[] = self::registerProfile($profile);
        }
        if (self::deamon_info()['state'] === 'ok') {
            self::deamon_start();
        }
        return $result;
    }

    /**
     * Retourne les profils publics sans leurs clés.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function profiles()
    {
        return self::profileStore()->listProfiles();
    }

    /**
     * Supprime un profil qui n'est référencé par aucun équipement Jeedom.
     *
     * @param string $profileId Identifiant interne.
     * @return bool
     */
    public static function removeProfile($profileId)
    {
        $usedBy = array();
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ((string) $eqLogic->getConfiguration('profile_id', '') === (string) $profileId) {
                $usedBy[] = $eqLogic->getName();
            }
        }
        if (count($usedBy) > 0) {
            throw new RuntimeException(sprintf(
                __('Ce profil est encore utilisé par : %s', __FILE__),
                implode(', ', $usedBy)
            ));
        }
        self::profileStore()->deleteProfile($profileId);
        log::add(__CLASS__, 'info', __('Profil Home Connect supprimé', __FILE__));
        return true;
    }

    /**
     * Tente de récupérer les profils avec les identifiants SingleKey enregistrés.
     *
     * @return array<int,array<string,mixed>> Profils importés.
     */
    public static function downloadProfilesFromHomeconnect()
    {
        $username = trim((string) config::byKey('homeconnect_username', __CLASS__, ''));
        $password = (string) config::byKey('homeconnect_password', __CLASS__, '');
        $service = new LocalHomeConnectCloudService(
            $username,
            $password,
            self::dataPath() . '/oauth',
            function ($level, $message) {
                log::add(__CLASS__, $level, $message);
            }
        );
        $archivePath = $service->downloadArchive();
        try {
            return self::importProfiles($archivePath);
        } finally {
            @unlink($archivePath);
        }
    }

    /**
     * Prépare un parcours OAuth PKCE à réaliser dans le navigateur.
     *
     * Le vérificateur PKCE et les cookies Home Connect ne sont jamais envoyés au
     * navigateur. Ils sont conservés dans le cache Jeedom pendant quinze minutes
     * et associés à un identifiant de flux aléatoire à usage unique.
     *
     * @return array{flowId:string,url:string,expiresIn:int}
     */
    public static function beginBrowserProfileAuthorization()
    {
        $service = new LocalHomeConnectCloudService(
            '',
            '',
            self::dataPath() . '/oauth',
            function ($level, $message) {
                log::add(__CLASS__, $level, $message);
            }
        );
        $authorization = $service->prepareBrowserAuthorization();
        $flowId = bin2hex(random_bytes(24));
        $cachedAuthorization = json_encode(array(
            'verifier' => $authorization['verifier'],
            'state' => $authorization['state'],
            'cookies' => $authorization['cookies'],
            'createdAt' => time(),
        ), JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($cachedAuthorization)) {
            throw new RuntimeException(__('Mémorisation de la session Home Connect impossible', __FILE__));
        }
        cache::set(self::browserOAuthCacheKey($flowId), utils::encrypt($cachedAuthorization), 900);
        log::add(__CLASS__, 'info', __('Autorisation Home Connect préparée pour le navigateur', __FILE__));
        return array(
            'flowId' => $flowId,
            'url' => $authorization['url'],
            'expiresIn' => 900,
        );
    }

    /**
     * Termine le parcours navigateur puis importe les profils téléchargés.
     *
     * @param string $flowId Identifiant de flux à usage unique.
     * @param string $redirectUrl URL Home Connect bloquée ou URL `hcauth://` finale.
     * @return array<int,array<string,mixed>> Profils importés.
     */
    public static function completeBrowserProfileAuthorization($flowId, $redirectUrl)
    {
        $flowId = strtolower(trim((string) $flowId));
        if (!preg_match('/^[a-f0-9]{48}$/', $flowId)) {
            throw new InvalidArgumentException(__('Session Home Connect invalide', __FILE__));
        }
        $redirectUrl = LocalHomeConnectCloudService::normalizeBrowserRedirect($redirectUrl);
        return self::withBrowserAuthorizationLock($flowId, function () use ($flowId, $redirectUrl) {
            return self::completeBrowserProfileAuthorizationLocked($flowId, $redirectUrl);
        });
    }

    /**
     * Finalise un parcours navigateur dont le verrou exclusif est détenu.
     *
     * @param string $flowId Identifiant validé.
     * @param string $redirectUrl Redirection normalisée.
     * @return array<int,array<string,mixed>> Profils importés.
     */
    private static function completeBrowserProfileAuthorizationLocked($flowId, $redirectUrl)
    {
        $cacheKey = self::browserOAuthCacheKey($flowId);
        $stored = (string) cache::byKey($cacheKey)->getValue();
        $decrypted = utils::decrypt($stored);
        $oauth = json_decode((string) $decrypted, true);
        // Compatibilité avec une autorisation commencée juste avant la mise à
        // jour, lorsque le cache de quinze minutes était encore en clair.
        if (!is_array($oauth)) {
            $oauth = json_decode($stored, true);
        }
        if (
            !is_array($oauth)
            || empty($oauth['verifier'])
            || empty($oauth['state'])
            || (int) ($oauth['createdAt'] ?? 0) < time() - 900
        ) {
            cache::delete($cacheKey);
            throw new RuntimeException(__('La session Home Connect a expiré. Recommencez depuis le bouton de récupération.', __FILE__));
        }

        if (stripos($redirectUrl, 'hcauth://') === 0) {
            $redirectParameters = array();
            parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $redirectParameters);
            if (!hash_equals((string) $oauth['state'], (string) ($redirectParameters['state'] ?? ''))) {
                cache::delete($cacheKey);
                throw new InvalidArgumentException(__('Cette adresse hcauth ne correspond pas à l’autorisation en cours', __FILE__));
            }
        }
        $service = new LocalHomeConnectCloudService(
            '',
            '',
            self::dataPath() . '/oauth',
            function ($level, $message) {
                log::add(__CLASS__, $level, $message);
            }
        );
        try {
            $archivePath = $service->downloadArchiveFromBrowserRedirect(
                $redirectUrl,
                (string) $oauth['verifier'],
                (string) $oauth['state'],
                (string) ($oauth['cookies'] ?? '')
            );
            try {
                $profiles = self::importProfiles($archivePath);
                cache::delete($cacheKey);
                return $profiles;
            } finally {
                @unlink($archivePath);
            }
        } catch (Throwable $exception) {
            if (self::isDefinitiveBrowserAuthorizationFailure($exception)) {
                cache::delete($cacheKey);
            }
            throw $exception;
        }
    }

    /**
     * Empêche deux requêtes web d'échanger simultanément le même code OAuth.
     *
     * @param string $flowId Identifiant validé.
     * @param callable $callback Opération protégée.
     * @return mixed
     */
    private static function withBrowserAuthorizationLock($flowId, $callback)
    {
        $directory = self::dataPath() . '/oauth';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(__('Création du répertoire OAuth impossible', __FILE__));
        }
        @chmod($directory, 0700);
        $path = $directory . '/.browser-' . $flowId . '.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException(__('Verrouillage de la session Home Connect impossible', __FILE__));
        }
        @chmod($path, 0600);
        if (!flock($handle, LOCK_EX)) {
            @fclose($handle);
            throw new RuntimeException(__('Verrouillage de la session Home Connect impossible', __FILE__));
        }
        try {
            return call_user_func($callback);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Distingue une erreur OAuth définitive d'une panne réseau réessayable.
     *
     * @param Throwable $exception Erreur reçue.
     * @return bool
     */
    private static function isDefinitiveBrowserAuthorizationFailure($exception)
    {
        if ($exception instanceof InvalidArgumentException) {
            return true;
        }
        return preg_match(
            '/autorisation.*refus|redirection.*invalide|ne correspond pas|invalid[_ -]?grant|code OAuth.*HTTP 4/iu',
            (string) $exception->getMessage()
        ) === 1;
    }

    /**
     * Retourne la clé de cache privée d'un parcours OAuth navigateur.
     *
     * @param string $flowId Identifiant aléatoire validé.
     * @return string
     */
    private static function browserOAuthCacheKey($flowId)
    {
        return 'localhomeconnect::browser_oauth::' . $flowId;
    }

    /**
     * Crée ou met à jour un équipement à partir d'un profil importé.
     *
     * @param array<string,mixed> $profile Profil public.
     * @return array<string,mixed>
     */
    private static function registerProfile($profile)
    {
        $haId = trim((string) ($profile['haId'] ?? ''));
        $eqLogic = self::byHaId($haId);
        $isNew = !is_object($eqLogic);
        if ($isNew) {
            $eqLogic = new self();
            $eqLogic->setEqType_name(__CLASS__);
            $eqLogic->setLogicalId('device_' . substr(sha1($haId), 0, 24));
            $label = trim((string) ($profile['name'] ?? ''));
            if ($label === '') {
                $label = trim(implode(' ', array_filter(array(
                    $profile['brand'] ?? '',
                    LocalHomeConnectTranslator::applianceType((string) ($profile['type'] ?? '')),
                ))));
            }
            $eqLogic->setName($label !== '' ? $label : __('Appareil Home Connect', __FILE__));
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setDisplay('widgetTmpl', 1);
        }
        $eqLogic->setConfiguration('ha_id', $haId);
        $eqLogic->setConfiguration('profile_id', (string) ($profile['profileId'] ?? ''));
        $eqLogic->setConfiguration('brand', (string) ($profile['brand'] ?? ''));
        $eqLogic->setConfiguration('device_type', (string) ($profile['type'] ?? ''));
        $eqLogic->setConfiguration('model', (string) ($profile['vib'] ?? ''));
        $eqLogic->setConfiguration('serial', (string) ($profile['serialNumber'] ?? ''));
        $eqLogic->setConfiguration('mac', (string) ($profile['mac'] ?? ''));
        $eqLogic->setConfiguration('connection_type', (string) ($profile['connectionType'] ?? ''));
        if ($isNew || trim((string) $eqLogic->getConfiguration('host', '')) === '') {
            $eqLogic->setConfiguration('host', self::suggestedHost($profile));
        }
        $eqLogic->setConfiguration('last_error', '');
        $eqLogic->save();
        return array(
            'id' => $eqLogic->getId(),
            'name' => $eqLogic->getName(),
            'haId' => $haId,
            'new' => $isNew,
        );
    }

    /**
     * Reprend une adresse explicitement fournie par le profil.
     *
     * Les noms d'instances Home Connect ne suivent pas une convention assez
     * stable pour être inventés. Sans adresse annoncée, la découverte mDNS
     * renseignera donc ce champ.
     *
     * @param array<string,mixed> $profile Profil.
     * @return string
     */
    private static function suggestedHost($profile)
    {
        foreach (array('host', 'address', 'ip') as $key) {
            $host = trim((string) ($profile[$key] ?? ''));
            if ($host !== '' && strlen($host) <= 253 && preg_match('/^[A-Za-z0-9._:-]+$/', $host)) {
                return $host;
            }
        }
        return '';
    }

    /**
     * Recherche un équipement par identifiant Home Connect stable.
     *
     * @param string $haId Identifiant Home Connect.
     * @return localhomeconnect|null
     */
    public static function byHaId($haId)
    {
        $logicalId = 'device_' . substr(sha1((string) $haId), 0, 24);
        $equipment = self::byLogicalId($logicalId, __CLASS__);
        if (is_object($equipment)) {
            return $equipment;
        }
        // Compatibilité avec les tout premiers équipements, créés avant
        // l'adoption du logicalId stable dérivé du haId.
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ((string) $eqLogic->getConfiguration('ha_id', '') === (string) $haId) {
                return $eqLogic;
            }
        }
        return null;
    }

    /**
     * Retourne l'exécutable Node.js disponible.
     *
     * @return string
     */
    private static function nodeBinary()
    {
        foreach (array('/usr/bin/node', '/usr/local/bin/node', '/usr/bin/nodejs') as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        $result = trim((string) shell_exec('command -v node 2>/dev/null'));
        return $result !== '' && is_executable($result) ? $result : '';
    }

    /**
     * Vérifie que la version de Node.js peut exécuter le démon.
     *
     * @param string $node Chemin de l'exécutable.
     * @return bool
     */
    private static function nodeVersionIsSupported($node)
    {
        if ($node === '') {
            return false;
        }
        $version = trim((string) shell_exec(escapeshellarg($node) . ' --version 2>/dev/null'));
        return preg_match('/^v(\d+)/', $version, $matches) === 1 && (int) $matches[1] >= 20;
    }

    /**
     * Retourne l'état des dépendances au format Jeedom.
     *
     * @return array<string,mixed>
     */
    public static function dependancy_info()
    {
        static $result = null;
        if (is_array($result)) {
            return $result;
        }
        $node = self::nodeBinary();
        $nodeReady = self::nodeVersionIsSupported($node);
        $nodeDirectory = realpath(__DIR__ . '/../../resources/node');
        $modulesReady = $nodeDirectory !== false;
        if ($modulesReady && $nodeReady) {
            $check = 'require.resolve("ws");require.resolve("fast-xml-parser");require.resolve("multicast-dns");';
            exec(
                'cd ' . escapeshellarg($nodeDirectory) . ' && ' . escapeshellarg($node)
                . ' -e ' . escapeshellarg($check) . ' 2>/dev/null',
                $output,
                $status
            );
            $modulesReady = $status === 0;
        } else {
            $modulesReady = false;
        }
        $phpReady = class_exists('ZipArchive')
            && class_exists('DOMDocument')
            && function_exists('simplexml_load_string')
            && function_exists('curl_init');
        $result = array(
            'log' => __CLASS__ . '_update',
            'progress_file' => jeedom::getTmpFolder(__CLASS__) . '/dependance',
            'state' => $nodeReady && $modulesReady && $phpReady ? 'ok' : 'nok',
        );
        return $result;
    }

    /**
     * Prépare l'installation des dépendances.
     *
     * @return array<string,string>
     */
    public static function dependancy_install()
    {
        log::remove(__CLASS__ . '_update');
        return array(
            'script' => '/bin/bash ' . escapeshellarg(realpath(__DIR__ . '/../../resources/install.sh'))
                . ' ' . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/dependance'),
            'log' => log::getPathToLog(__CLASS__ . '_update'),
        );
    }

    /**
     * Retourne le port du serveur local du démon.
     *
     * @return int
     */
    private static function daemonPort()
    {
        $port = (int) config::byKey('daemon_port', __CLASS__, 55043);
        return $port >= 1024 && $port <= 65535 ? $port : 55043;
    }

    /**
     * Retourne le fichier PID privé du démon.
     *
     * @return string
     */
    private static function daemonPidPath()
    {
        return self::dataPath() . '/localhomeconnectd.pid';
    }

    /**
     * Vérifie qu'un PID désigne exactement le démon de cette installation.
     *
     * @param int $pid Identifiant de processus.
     * @return bool
     */
    private static function daemonPidIsValid($pid)
    {
        $pid = (int) $pid;
        if ($pid <= 1 || !is_readable('/proc/' . $pid . '/cmdline')) {
            return false;
        }
        $arguments = array_values(array_filter(
            explode("\0", (string) file_get_contents('/proc/' . $pid . '/cmdline')),
            function ($argument) {
                return $argument !== '';
            }
        ));
        $script = realpath(__DIR__ . '/../../resources/localhomeconnectd.js');
        $configuration = realpath(self::dataPath() . '/daemon-config.json');
        return $script !== false
            && $configuration !== false
            && in_array($script, $arguments, true)
            && in_array('--config=' . $configuration, $arguments, true);
    }

    /**
     * Crée si nécessaire un secret persistant pour l'API locale du démon.
     *
     * @return string
     */
    private static function daemonToken()
    {
        $token = trim((string) config::byKey('daemon_token', __CLASS__, ''));
        if (strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            config::save('daemon_token', $token, __CLASS__);
        }
        return $token;
    }

    /**
     * Crée si nécessaire l'identité locale de l'application Home Connect.
     *
     * @return string
     */
    private static function applicationId()
    {
        $appId = trim((string) config::byKey('app_id', __CLASS__, ''));
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $appId)) {
            $appId = 'jeedom-' . substr(bin2hex(random_bytes(12)), 0, 24);
            config::save('app_id', $appId, __CLASS__);
        }
        return $appId;
    }

    /**
     * Interroge l'API HTTP privée du démon.
     *
     * @param string $path Route locale.
     * @param array<string,mixed> $payload Corps JSON.
     * @param string $method Méthode HTTP.
     * @param int $timeout Délai maximal en secondes.
     * @return array<string,mixed>
     */
    public static function daemonRequest($path, $payload = array(), $method = 'POST', $timeout = 30)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(__('L’extension PHP cURL est nécessaire', __FILE__));
        }
        $curl = curl_init('http://127.0.0.1:' . self::daemonPort() . '/' . ltrim($path, '/'));
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => max(5, min(180, (int) $timeout)),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . self::daemonToken(),
                'Content-Type: application/json',
            ),
        ));
        if ($method !== 'GET') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        unset($curl);
        if ($response === false || $status < 200 || $status >= 300) {
            $decoded = json_decode((string) $response, true);
            $message = is_array($decoded) && !empty($decoded['error'])
                ? (string) $decoded['error']
                : ($error !== '' ? $error : sprintf(__('HTTP %d', __FILE__), $status));
            throw new RuntimeException($message);
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(__('Réponse invalide du démon', __FILE__));
        }
        return $decoded;
    }

    /**
     * Retourne l'état du démon Jeedom.
     *
     * @return array<string,mixed>
     */
    public static function deamon_info()
    {
        $return = array(
            'log' => __CLASS__,
            'state' => 'nok',
            'launchable' => 'ok',
            'devices' => array(),
        );
        if (self::dependancy_info()['state'] !== 'ok') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Les dépendances Node.js ne sont pas installées', __FILE__);
            return $return;
        }
        $enabled = array_filter(self::byType(__CLASS__), function ($eqLogic) {
            return $eqLogic->getIsEnable() && trim((string) $eqLogic->getConfiguration('profile_id', '')) !== '';
        });
        if (count($enabled) === 0) {
            $profiles = self::profiles();
            if (count($profiles) > 0) {
                foreach ($profiles as $profile) {
                    if (!isset($profile['haId'], $profile['profileId'])) {
                        continue;
                    }
                    $haId = trim((string) $profile['haId']);
                    if ($haId === '') {
                        continue;
                    }
                    $existing = self::byHaId($haId);
                    if (is_object($existing) && trim((string) $existing->getConfiguration('profile_id', '')) !== '') {
                        if (!$existing->getIsEnable()) {
                            $existing->setIsEnable(1);
                            $existing->save();
                        }
                        continue;
                    }
                    try {
                        self::registerProfile($profile);
                    } catch (Throwable $exception) {
                        log::add(__CLASS__, 'warning', __('Réconciliation automatique du profil impossible pour', __FILE__) . ' ' . $haId . ' : ' . $exception->getMessage());
                    }
                }
                $enabled = array_filter(self::byType(__CLASS__), function ($eqLogic) {
                    return $eqLogic->getIsEnable() && trim((string) $eqLogic->getConfiguration('profile_id', '')) !== '';
                });
            }
        }
        if (count($enabled) === 0) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Importez au moins un profil Home Connect', __FILE__);
            return $return;
        }
        try {
            $health = self::daemonRequest('health', array(), 'GET');
            if (!empty($health['success'])) {
                $return['state'] = 'ok';
                $return['pid'] = (int) ($health['pid'] ?? 0);
                $return['node_version'] = (string) ($health['nodeVersion'] ?? '');
                $return['started_at'] = (string) ($health['startedAt'] ?? '');
                $return['uptime_seconds'] = max(0, (int) ($health['uptimeSeconds'] ?? 0));
                $return['memory_rss_mb'] = max(0, (int) ($health['memoryRssMb'] ?? 0));
                $return['log_level'] = (string) ($health['logLevel'] ?? '');
                $return['log_format'] = (string) ($health['logFormat'] ?? 'legacy');
                $return['debug_protocol'] = !empty($health['debugProtocol']);
                $return['devices'] = is_array($health['devices'] ?? null) ? $health['devices'] : array();
            }
        } catch (Exception $exception) {
            $return['state'] = 'nok';
        }
        return $return;
    }

    /**
     * Retourne le niveau de journalisation effectif configuré dans Jeedom.
     *
     * @return string Niveau PSR compris par le démon.
     */
    private static function daemonLogLevel()
    {
        return (string) log::convertLogLevel(log::getLogLevel(__CLASS__));
    }

    /**
     * Convertit la valeur brute du sélecteur de niveau Jeedom.
     *
     * Cette conversion évite de relire le cache statique de `log` pendant la
     * requête qui vient précisément de modifier la configuration.
     *
     * @param mixed $configuration Valeur reçue par le hook postConfig.
     * @return string Niveau PSR compris par le démon.
     */
    private static function daemonLogLevelFromConfiguration($configuration)
    {
        if (is_string($configuration)) {
            $decoded = json_decode($configuration, true);
            if (is_array($decoded)) {
                $configuration = $decoded;
            }
        }
        if (!is_array($configuration)) {
            return self::daemonLogLevel();
        }
        if (!empty($configuration['default'])) {
            return (string) log::convertLogLevel((int) config::byKey('log::level', 'core', 200));
        }
        foreach ($configuration as $level => $enabled) {
            if (is_numeric($level) && (int) $enabled === 1) {
                return (string) log::convertLogLevel((int) $level);
            }
        }
        return self::daemonLogLevel();
    }

    /**
     * Met à jour la journalisation du démon en cours d'exécution.
     *
     * L'absence du démon est normale pendant l'installation ou lorsqu'aucun
     * profil n'est actif ; la configuration sera appliquée à son prochain
     * démarrage.
     *
     * @param array<string,mixed> $configuration Valeurs à actualiser.
     * @return void
     */
    private static function synchronizeDaemonLogging($configuration)
    {
        try {
            self::daemonRequest('logging', $configuration);
        } catch (Throwable $exception) {
            // L'absence du démon est normale pendant l'installation. Une mise à
            // jour du plugin recharge déjà une fois le processus Node ; ne pas
            // le redémarrer ici évite deux relances lors d'une même sauvegarde.
        }
    }

    /**
     * Applique immédiatement au démon le niveau de log du plugin.
     *
     * @param mixed $value Configuration Jeedom du niveau spécifique.
     * @return void
     */
    public static function postConfig_log_level_localhomeconnect($value)
    {
        self::synchronizeDaemonLogging(array(
            'logLevel' => self::daemonLogLevelFromConfiguration($value),
        ));
    }

    /**
     * Applique immédiatement l'option de traces protocolaires détaillées.
     *
     * @param mixed $value Valeur enregistrée par Jeedom.
     * @return void
     */
    public static function postConfig_debug_protocol($value)
    {
        self::synchronizeDaemonLogging(array(
            'debugProtocol' => (int) $value === 1,
        ));
    }

    /**
     * Écrit la configuration privée consommée par le démon.
     *
     * @return string Chemin du fichier.
     */
    private static function writeDaemonConfiguration()
    {
        $devices = array();
        $profileStore = self::profileStore();
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if (!$eqLogic->getIsEnable()) {
                continue;
            }
            $profileId = trim((string) $eqLogic->getConfiguration('profile_id', ''));
            if ($profileId === '') {
                continue;
            }
            try {
                $profileStore->getProfile($profileId);
                $profilePath = $profileStore->getProfilePath($profileId);
            } catch (Throwable $exception) {
                $message = sprintf(
                    __('Profil ignoré pour [%s] : %s', __FILE__),
                    $eqLogic->getHumanName(),
                    $exception->getMessage()
                );
                log::add(__CLASS__, 'error', $message);
                $eqLogic->setConfiguration('last_error', $message);
                $eqLogic->checkAndUpdateCmd('connected', 0);
                if ($eqLogic->getChanged()) {
                    $eqLogic->save(true);
                }
                continue;
            }
            $devices[] = array(
                'profilePath' => $profilePath,
                'host' => trim((string) $eqLogic->getConfiguration('host', '')),
            );
        }
        if (count($devices) === 0) {
            throw new RuntimeException(__('Aucun profil Home Connect actif n’est exploitable', __FILE__));
        }
        $baseUrl = rtrim((string) network::getNetworkAccess('internal'), '/');
        $configuration = array(
            'daemonPort' => self::daemonPort(),
            'daemonToken' => self::daemonToken(),
            'callbackUrl' => $baseUrl . '/plugins/localhomeconnect/core/php/callback.php',
            'apiKey' => jeedom::getApiKey(__CLASS__),
            'callbackVerifyTls' => (int) config::byKey('callback_verify_tls', __CLASS__, 1) === 1,
            'appName' => trim((string) config::byKey('app_name', __CLASS__, 'Jeedom LocalHomeConnect')),
            'appId' => self::applicationId(),
            'timezone' => date_default_timezone_get(),
            'discoveryTimeout' => max(2, min(30, (int) config::byKey('discovery_timeout', __CLASS__, 8))),
            'reconnectInterval' => max(5, (int) config::byKey('reconnect_interval', __CLASS__, 30)),
            'watchdogInterval' => max(60, (int) config::byKey('watchdog_interval', __CLASS__, 600)),
            'callbackRetryDuration' => 120,
            'logLevel' => self::daemonLogLevel(),
            'debugProtocol' => (int) config::byKey('debug_protocol', __CLASS__, 0) === 1,
            'pidFile' => self::daemonPidPath(),
            'devices' => $devices,
        );
        $encoded = json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new RuntimeException(__('Encodage de la configuration du démon impossible', __FILE__));
        }
        $path = self::dataPath() . '/daemon-config.json';
        $temporary = tempnam(self::dataPath(), '.daemon-');
        if ($temporary === false) {
            throw new RuntimeException(__('Écriture de la configuration du démon impossible', __FILE__));
        }
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException(__('Écriture de la configuration du démon impossible', __FILE__));
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException(__('Installation de la configuration du démon impossible', __FILE__));
        }
        chmod($path, 0600);
        return $path;
    }

    /**
     * Démarre le démon WebSocket local.
     *
     * @param bool $debug Paramètre Jeedom conservé pour compatibilité.
     * @return bool
     */
    public static function deamon_start($debug = false)
    {
        return self::withDaemonLifecycleLock(function () use ($debug) {
            return self::deamonStartUnlocked($debug);
        });
    }

    /**
     * Démarre le démon alors que le verrou de cycle de vie est détenu.
     *
     * @param bool $debug Paramètre Jeedom conservé pour compatibilité.
     * @return bool
     */
    private static function deamonStartUnlocked($debug = false)
    {
        $info = self::deamon_info();
        if ($info['launchable'] !== 'ok') {
            throw new RuntimeException((string) ($info['launchable_message'] ?? __('Démon non lançable', __FILE__)));
        }
        self::deamonStopUnlocked();
        $node = self::nodeBinary();
        $script = realpath(__DIR__ . '/../../resources/localhomeconnectd.js');
        $configPath = self::writeDaemonConfiguration();
        $command = escapeshellarg($node)
            . ' ' . escapeshellarg($script)
            . ' --config=' . escapeshellarg($configPath)
            . ' >> ' . escapeshellarg(log::getPathToLog(__CLASS__)) . ' 2>&1 &';
        log::add(__CLASS__, 'info', __('Lancement du démon LocalHomeConnect', __FILE__));
        exec($command);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            usleep(500000);
            try {
                $health = self::daemonRequest('health', array(), 'GET');
                if (!empty($health['success'])) {
                    if ((string) ($health['logFormat'] ?? '') !== 'jeedom') {
                        log::add(
                            __CLASS__,
                            'error',
                            __('Le démon lancé utilise encore une ancienne copie de resources/node/daemon.js', __FILE__)
                        );
                    }
                    return true;
                }
            } catch (Exception $exception) {
                // Le serveur local est encore en cours d'initialisation.
            }
        }
        log::add(__CLASS__, 'error', __('Impossible de lancer le démon LocalHomeConnect', __FILE__));
        return false;
    }

    /**
     * Arrête uniquement le processus identifié par le PID privé du démon.
     *
     * @return bool
     */
    public static function deamon_stop()
    {
        return self::withDaemonLifecycleLock(function () {
            return self::deamonStopUnlocked();
        });
    }

    /**
     * Arrête le démon alors que le verrou de cycle de vie est détenu.
     *
     * @return bool
     */
    private static function deamonStopUnlocked()
    {
        $pidPath = self::daemonPidPath();
        $pid = is_readable($pidPath) ? (int) trim((string) file_get_contents($pidPath)) : 0;
        if (!self::daemonPidIsValid($pid)) {
            try {
                $health = self::daemonRequest('health', array(), 'GET');
                $candidate = (int) ($health['pid'] ?? 0);
                if (self::daemonPidIsValid($candidate)) {
                    $pid = $candidate;
                }
            } catch (Throwable $exception) {
                // Le démon est déjà arrêté ou son ancien PID est périmé.
            }
        }
        if (self::daemonPidIsValid($pid)) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
            } else {
                system::kill($pid);
            }
            for ($attempt = 0; $attempt < 30 && self::daemonPidIsValid($pid); $attempt++) {
                usleep(100000);
            }
            if (self::daemonPidIsValid($pid)) {
                system::kill($pid);
            }
        }
        if (is_file($pidPath) && !self::daemonPidIsValid((int) trim((string) @file_get_contents($pidPath)))) {
            @unlink($pidPath);
        }
        return true;
    }

    /**
     * Démarre automatiquement le démon lorsqu'il n'est plus joignable.
     *
     * @return bool Vrai lorsque le démon a dû être relancé.
     */
    public static function ensureDaemonRunning()
    {
        if ((string) (self::deamon_info()['state'] ?? 'nok') === 'ok') {
            return false;
        }
        return self::withDaemonLifecycleLock(function () {
            if ((string) (self::deamon_info()['state'] ?? 'nok') === 'ok') {
                return false;
            }
            log::add(__CLASS__, 'warning', __('Le démon est arrêté, tentative de redémarrage automatique', __FILE__));
            if (!self::deamonStartUnlocked()) {
                throw new RuntimeException(__('Le démon LocalHomeConnect est arrêté et son redémarrage automatique a échoué', __FILE__));
            }
            return true;
        });
    }

    /**
     * Sérialise les démarrages et arrêts demandés par plusieurs requêtes PHP.
     *
     * @param callable $callback Opération protégée.
     * @return mixed
     */
    private static function withDaemonLifecycleLock($callback)
    {
        if (self::$daemonLifecycleLockDepth > 0) {
            self::$daemonLifecycleLockDepth++;
            try {
                return call_user_func($callback);
            } finally {
                self::$daemonLifecycleLockDepth--;
            }
        }
        $path = self::dataPath() . '/.daemon.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException(__('Verrouillage du démon impossible', __FILE__));
        }
        @chmod($path, 0600);
        if (!flock($handle, LOCK_EX)) {
            @fclose($handle);
            throw new RuntimeException(__('Verrouillage du démon impossible', __FILE__));
        }
        self::$daemonLifecycleLockHandle = $handle;
        self::$daemonLifecycleLockDepth = 1;
        try {
            return call_user_func($callback);
        } finally {
            self::$daemonLifecycleLockDepth = 0;
            self::$daemonLifecycleLockHandle = null;
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Appelle le démon et effectue une seule relance si celui-ci disparaît
     * entre le contrôle de santé et la requête.
     *
     * @param string $path Route du démon.
     * @param array<string,mixed> $payload Données JSON.
     * @param string $method Méthode HTTP.
     * @param int $timeout Délai maximal en secondes.
     * @return array<string,mixed>
     */
    public static function daemonRequestWithRecovery($path, $payload = array(), $method = 'POST', $timeout = 30)
    {
        self::ensureDaemonRunning();
        try {
            return self::daemonRequest($path, $payload, $method, $timeout);
        } catch (Exception $exception) {
            if ((string) (self::deamon_info()['state'] ?? 'nok') === 'ok') {
                throw $exception;
            }
            log::add(__CLASS__, 'warning', __('Le démon s’est interrompu pendant la requête, nouvelle tentative', __FILE__));
            if (!self::deamon_start()) {
                throw new RuntimeException(__('Le démon LocalHomeConnect ne peut pas être redémarré automatiquement', __FILE__));
            }
            return self::daemonRequest($path, $payload, $method, $timeout);
        }
    }

    /**
     * Lance une découverte mDNS depuis le démon.
     *
     * @return array<string,mixed>
     */
    public static function synchronize()
    {
        return self::daemonRequestWithRecovery('discover');
    }

    /**
     * Relit immédiatement un appareil.
     *
     * @param int $eqLogicId Identifiant Jeedom.
     * @return array<string,mixed>
     */
    public static function refreshEquipment($eqLogicId)
    {
        $eqLogic = self::byId((int) $eqLogicId);
        if (!is_object($eqLogic)) {
            throw new InvalidArgumentException(__('Équipement introuvable', __FILE__));
        }
        $result = self::daemonRequestWithRecovery('refresh', array(
            'haId' => $eqLogic->getConfiguration('ha_id'),
            'host' => trim((string) $eqLogic->getConfiguration('host', '')),
        ), 'POST', 120);
        if (is_array($result['health'] ?? null)) {
            self::applyConnectionStatus($eqLogic, $result['health']);
            $eqLogic->refreshWidget();
        }
        return $result;
    }

    /**
     * Teste la communication et retourne un résumé lisible.
     *
     * @param int $eqLogicId Identifiant Jeedom.
     * @return array<string,mixed>
     */
    public static function testCommunication($eqLogicId)
    {
        $started = microtime(true);
        $result = self::refreshEquipment($eqLogicId);
        return array(
            'success' => !empty($result['success']),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'health' => $result['health'] ?? array(),
        );
    }

    /**
     * Reçoit une notification authentifiée du démon.
     *
     * @param array<string,mixed> $payload Événement JSON.
     * @return void
     */
    public static function handleDaemonEvent($payload)
    {
        if (!is_array($payload)) {
            throw new InvalidArgumentException(__('Événement du démon invalide', __FILE__));
        }
        $haId = trim((string) ($payload['haId'] ?? ''));
        $eqLogic = self::byHaId($haId);
        if (!is_object($eqLogic)) {
            throw new RuntimeException(__('Appareil Home Connect inconnu', __FILE__));
        }
        $event = (string) ($payload['event'] ?? '');
        if ($event === 'discovery') {
            $host = trim((string) ($payload['host'] ?? ''));
            if ($host !== '') {
                $eqLogic->setConfiguration('host', $host)->save(true);
            }
            return;
        }
        if ($event === 'status') {
            self::applyConnectionStatus($eqLogic, $payload);
            return;
        }
        if ($event === 'values') {
            self::applyConnectionStatus($eqLogic, $payload);
            if (!empty($payload['lastSeen'])) {
                $timestamp = strtotime((string) $payload['lastSeen']);
                if ($timestamp !== false) {
                    $eqLogic->setStatus('lastCommunication', date('Y-m-d H:i:s', $timestamp));
                }
            }
            self::updateEntityValues(
                $eqLogic,
                is_array($payload['entities'] ?? null) ? $payload['entities'] : array()
            );
            self::updateProgramValues(
                $eqLogic,
                $payload['selectedProgram'] ?? null,
                (string) ($payload['selectedProgramName'] ?? ''),
                $payload['activeProgram'] ?? null,
                (string) ($payload['activeProgramName'] ?? '')
            );
            return;
        }
        if (!in_array($event, array('schema', 'snapshot'), true)) {
            throw new InvalidArgumentException(__('Type d’événement inconnu', __FILE__));
        }
        self::applyConnectionStatus($eqLogic, $payload);
        $info = is_array($payload['info'] ?? null) ? $payload['info'] : array();
        foreach (array(
            'brand' => 'brand',
            'vib' => 'model',
            'deviceType' => 'device_type',
            'serialNumber' => 'serial',
            'mac' => 'mac',
            'swVersion' => 'software_version',
            'hwVersion' => 'hardware_version',
        ) as $source => $target) {
            if (isset($info[$source]) && trim((string) $info[$source]) !== '') {
                $eqLogic->setConfiguration($target, (string) $info[$source]);
            }
        }
        if ($eqLogic->getChanged()) {
            $eqLogic->save(true);
        }
        self::syncEntities(
            $eqLogic,
            is_array($payload['entities'] ?? null) ? $payload['entities'] : array(),
            is_array($payload['knownUids'] ?? null) ? $payload['knownUids'] : array(),
            is_array($payload['writableUids'] ?? null) ? $payload['writableUids'] : array()
        );
        self::syncPrograms(
            $eqLogic,
            is_array($payload['programs'] ?? null) ? $payload['programs'] : array(),
            $payload['selectedProgram'] ?? null,
            $payload['activeProgram'] ?? null,
            !empty($payload['canSelectProgram']),
            !empty($payload['canStartProgram'])
        );
        $eqLogic->refreshWidget();
    }

    /**
     * Met à jour l'état de connexion et les diagnostics de l'équipement.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @param array<string,mixed> $payload Événement.
     * @return void
     */
    public static function applyConnectionStatus($eqLogic, $payload)
    {
        $connected = !empty($payload['connected']);
        // Une valeur `connected` n'est émise qu'après la négociation chiffrée,
        // l'authentification et l'ouverture du protocole Home Connect. Les
        // anciennes versions du démon pouvaient encore envoyer ready=false
        // lorsqu'une ressource RO facultative manquait : Jeedom normalise cet
        // ancien état pour ne plus afficher un faux mode dégradé.
        $host = trim((string) ($payload['host'] ?? ''));
        if ($host !== '') {
            $eqLogic->setConfiguration('host', $host);
        }
        if (array_key_exists('error', $payload)) {
            $eqLogic->setConfiguration('last_error', (string) $payload['error']);
        }
        $eqLogic->setConfiguration('runtime_transport', (string) ($payload['transport'] ?? ''));
        $eqLogic->setConfiguration('reconnect_failures', max(0, (int) ($payload['reconnectFailures'] ?? 0)));
        if (!empty($payload['lastSeen'])) {
            $timestamp = strtotime((string) $payload['lastSeen']);
            if ($timestamp !== false) {
                $eqLogic->setStatus('lastCommunication', date('Y-m-d H:i:s', $timestamp));
            }
        }
        $eqLogic->checkAndUpdateCmd('connected', $connected ? 1 : 0);
        if ($eqLogic->getChanged()) {
            $eqLogic->save(true);
        }
    }

    /**
     * Met uniquement à jour les valeurs reçues, sans réenregistrer le schéma.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @param array<int,array<string,mixed>> $entities Valeurs modifiées.
     * @return void
     */
    private static function updateEntityValues($eqLogic, $entities)
    {
        $commandsByUid = array();
        foreach ((array) $eqLogic->getCmd('info') as $command) {
            $uid = (int) $command->getConfiguration('uid', 0);
            if ($uid > 0 && !isset($commandsByUid[$uid])) {
                $commandsByUid[$uid] = $command;
            }
        }
        foreach ($entities as $entity) {
            if (!is_array($entity) || empty($entity['uid']) || empty($entity['feature'])) {
                continue;
            }
            $uid = (int) ($entity['uidNumber'] ?? hexdec((string) $entity['uid']));
            $logicalId = self::entityLogicalId((string) $entity['uid'], (string) $entity['feature']);
            $info = $eqLogic->getCmd('info', $logicalId);
            if (!is_object($info)) {
                $info = $commandsByUid[$uid] ?? null;
            }
            if (!is_object($info) || !empty($entity['valueMissing']) || !empty($entity['commandOnly'])) {
                continue;
            }
            $eqLogic->checkAndUpdateCmd($info->getLogicalId(), self::entityValue($info, $entity));
        }
    }

    /**
     * Met à jour les états de programme sans sauvegarder leurs commandes.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @param mixed $selected Identifiant sélectionné.
     * @param string $selectedName Nom brut sélectionné.
     * @param mixed $active Identifiant actif.
     * @param string $activeName Nom brut actif.
     * @return void
     */
    private static function updateProgramValues($eqLogic, $selected, $selectedName, $active, $activeName)
    {
        $eqLogic->checkAndUpdateCmd(
            'selected_program',
            $selectedName !== '' ? LocalHomeConnectTranslator::program($selectedName) : ''
        );
        $eqLogic->checkAndUpdateCmd(
            'active_program',
            $activeName !== '' ? LocalHomeConnectTranslator::program($activeName) : ''
        );
        $eqLogic->checkAndUpdateCmd('selected_program_uid', is_numeric($selected) ? (int) $selected : 0);
    }

    /**
     * Retourne l'identifiant Jeedom stable d'une information Home Connect.
     *
     * @param string $uid UID hexadécimal.
     * @param string $feature Fonction Home Connect.
     * @return string
     */
    private static function entityLogicalId($uid, $feature)
    {
        $normalized = strtolower(preg_replace('/[^0-9a-f]/i', '', (string) $uid));
        return 'info_uid_' . ($normalized !== '' ? $normalized : substr(sha1((string) $feature), 0, 12));
    }

    /**
     * Convertit une valeur d'entité avec les règles de la commande existante.
     *
     * @param localhomeconnectCmd $info Commande Jeedom.
     * @param array<string,mixed> $entity Entité reçue.
     * @return mixed
     */
    private static function entityValue($info, $entity)
    {
        $feature = (string) ($entity['feature'] ?? $info->getConfiguration('feature', ''));
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : array();
        $states = is_array($metadata['states'] ?? null) ? $metadata['states'] : array();
        $binary = self::binaryStateMap($states);
        if ($binary === null && strtolower((string) ($metadata['type'] ?? '')) === 'boolean') {
            $binary = array('false' => 0, 'true' => 1);
        }
        if ($binary !== null) {
            $raw = (string) ($entity['rawValue'] ?? '');
            return isset($binary[$raw]) ? $binary[$raw] : (int) !empty($entity['rawValue']);
        }
        if ((int) $info->getConfiguration('manual_override', 0) === 1) {
            $manualStates = $info->getConfiguration('states', array());
            $raw = (string) ($entity['rawValue'] ?? '');
            if (is_array($manualStates) && array_key_exists($raw, $manualStates)) {
                return $manualStates[$raw];
            }
        }
        return LocalHomeConnectTranslator::value($feature, $entity['value'] ?? '');
    }

    /**
     * Crée ou actualise toutes les commandes issues d'un instantané.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @param array<int,array<string,mixed>> $entities Capacités.
     * @param string[] $knownUids UIDs présents dans le XML complet.
     * @param string[] $writableUids UIDs inscriptibles dans le XML complet.
     * @return void
     */
    private static function syncEntities($eqLogic, $entities, $knownUids = array(), $writableUids = array())
    {
        $entityLimit = 1500;
        if (count($entities) > $entityLimit) {
            log::add(__CLASS__, 'warning', sprintf(
                __('%d commande(s) Home Connect ignorée(s) : la limite de %d entités par équipement est atteinte', __FILE__),
                count($entities) - $entityLimit,
                $entityLimit
            ));
        }
        $known = array();
        foreach ($knownUids as $uid) {
            $known[(int) hexdec((string) $uid)] = true;
        }
        $writable = array();
        foreach ($writableUids as $uid) {
            $writable[(int) hexdec((string) $uid)] = true;
        }
        $processedActionUids = array();
        $expectedActionLogicalIds = array();
        self::retireLegacyProgramRootCommands($eqLogic);
        $nameIndex = self::commandNameIndex($eqLogic);
        $infoByUid = array();
        foreach ((array) $eqLogic->getCmd('info') as $existingInfo) {
            $existingUid = (int) $existingInfo->getConfiguration('uid', 0);
            if ($existingUid > 0 && !isset($infoByUid[$existingUid])) {
                $infoByUid[$existingUid] = $existingInfo;
            }
        }
        foreach (array_slice($entities, 0, $entityLimit) as $entity) {
            if (!is_array($entity) || empty($entity['uid']) || empty($entity['feature'])) {
                continue;
            }
            $uid = strtoupper((string) $entity['uid']);
            $uidNumber = (int) ($entity['uidNumber'] ?? hexdec($uid));
            $feature = (string) $entity['feature'];
            $isProgramRoot = in_array($feature, array(
                'BSH.Common.Root.SelectedProgram',
                'BSH.Common.Root.ActiveProgram',
            ), true);
            if ($isProgramRoot) {
                continue;
            }
            $logicalId = self::entityLogicalId($uid, $feature);
            $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : array();
            foreach (LocalHomeConnectTranslator::metadata($feature) as $key => $value) {
                if (!isset($metadata[$key]) || $metadata[$key] === '') {
                    $metadata[$key] = $value;
                }
            }
            // Le démon déduit d'abord le type du XML. Le dictionnaire PHP
            // complète les quelques commandes dont le profil ne fournit ni
            // valeur courante ni type explicite (notamment les impulsions).
            $metadata['protocolType'] = self::protocolTypeFromMetadata($metadata);
            $entity['metadata'] = $metadata;
            $states = is_array($metadata['states'] ?? null) ? $metadata['states'] : array();
            $binary = self::binaryStateMap($states);
            $declaredType = strtolower((string) ($metadata['type'] ?? ''));
            if ($binary === null && $declaredType === 'boolean') {
                $binary = array('false' => 0, 'true' => 1);
            }
            $subType = (string) ($entity['subtype'] ?? 'string');
            if ($binary !== null) {
                $subType = 'binary';
            } elseif (count($states) > 0 || strpos($declaredType, 'enum') !== false) {
                $subType = 'string';
            } elseif (in_array($declaredType, array('int', 'integer', 'float', 'double'), true)) {
                $subType = 'numeric';
            } elseif ($declaredType === 'string') {
                $subType = 'string';
            }
            if (!in_array($subType, array('binary', 'numeric', 'string'), true)) {
                $subType = 'string';
            }
            $info = $eqLogic->getCmd('info', $logicalId);
            if (!is_object($info) && isset($infoByUid[$uidNumber])) {
                $info = $infoByUid[$uidNumber];
                if (is_object($info) && !is_object($eqLogic->getCmd('info', $logicalId))) {
                    $info->setLogicalId($logicalId);
                }
            }
            $isNewCommand = !is_object($info);
            if (!is_object($info)) {
                $info = new localhomeconnectCmd();
                $info->setEqLogic_id($eqLogic->getId());
                $info->setLogicalId($logicalId);
            }
            $infoByUid[$uidNumber] = $info;
            $manualOverride = !$isNewCommand && (int) $info->getConfiguration('manual_override', 0) === 1;
            $translatedName = LocalHomeConnectTranslator::feature($feature);
            if (!empty($entity['commandOnly'])) {
                $translatedName = __('État technique', __FILE__) . ' - ' . $translatedName;
            }
            $info->setType('info');
            $hasCurrentValue = empty($entity['valueMissing']) && empty($entity['commandOnly']);
            $translatedStates = array();
            foreach ($states as $rawState => $stateLabel) {
                $translatedStates[$rawState] = LocalHomeConnectTranslator::value($feature, $stateLabel);
            }
            if (!$manualOverride) {
                $info->setName(self::uniqueCommandName($nameIndex, $info, $translatedName, $uidNumber));
                $info->setSubType($subType);
                $technicalHobGeometry = preg_match(
                    '/Cooking\.Hob\.Status(?:\.Zone\.\d+)?\.(?:Position|LengthX|LengthY|Shape|ZoneSelector)$/i',
                    $feature
                ) === 1;
                $info->setIsVisible(
                    $hasCurrentValue
                    && !$technicalHobGeometry
                    && in_array((string) ($entity['category'] ?? ''), array('status', 'program', 'phases', 'options', 'settings', 'events'), true)
                    ? 1
                    : 0
                );
                $info->setIsHistorized(self::shouldHistorize($feature, $metadata, $subType) ? 1 : 0);
                $info->setConfiguration('uid', (int) ($entity['uidNumber'] ?? hexdec($uid)));
                $info->setConfiguration('uid_hex', $uid);
                $info->setConfiguration('feature', $feature);
                $info->setConfiguration('category', (string) ($entity['category'] ?? 'information'));
                $info->setConfiguration('generated', 1);
                $info->setConfiguration('obsolete', 0);
                $info->setConfiguration('states', $translatedStates);
                $info->setConfiguration('protocol_value_type', (string) ($metadata['protocolType'] ?? 'string'));
                $info->setConfiguration('minValue', isset($metadata['min']) && is_numeric($metadata['min']) ? (float) $metadata['min'] : '');
                $info->setConfiguration('maxValue', isset($metadata['max']) && is_numeric($metadata['max']) ? (float) $metadata['max'] : '');
                $info->setConfiguration('step', isset($metadata['step']) && is_numeric($metadata['step']) ? (float) $metadata['step'] : '');
                // Jeedom ne doit afficher une unité que pour une information
                // numérique. Les états booléens et les énumérations n'en ont
                // pas, même si un ancien profil leur en attribuait une.
                $unit = $subType === 'numeric'
                    ? self::normalizeUnit((string) ($metadata['unit'] ?? ''))
                    : '';
                $info->setUnite($unit);
            } elseif ((int) $info->getConfiguration('obsolete', 0) === 1) {
                $info->setConfiguration('obsolete', 0);
                $info->setIsVisible((int) $info->getConfiguration('visible_before_obsolete', 1));
            }
            self::saveCommandIfChanged($info);

            if ($hasCurrentValue) {
                $eqLogic->checkAndUpdateCmd($logicalId, self::entityValue($info, $entity));
            }

            if (empty($entity['writable']) || !empty($entity['dangerous'])) {
                continue;
            }
            $processedActionUids[$uidNumber] = true;
            foreach (self::syncEntityActions($eqLogic, $info, $entity, $translatedStates, $binary, $nameIndex) as $actionLogicalId) {
                $expectedActionLogicalIds[$actionLogicalId] = true;
            }
        }
        if (count($known) === 0) {
            return;
        }
        $preferredInfoByUid = array();
        $markedObsolete = 0;
        $restored = 0;
        foreach ((array) $eqLogic->getCmd('info') as $information) {
            $informationUid = (int) $information->getConfiguration('uid', 0);
            if ($informationUid > 0 && strpos((string) $information->getLogicalId(), 'info_uid_') === 0) {
                $preferredInfoByUid[$informationUid] = (int) $information->getId();
            }
        }
        foreach ((array) $eqLogic->getCmd() as $command) {
            $commandUid = (int) $command->getConfiguration('uid', 0);
            if ((int) $command->getConfiguration('generated', 0) !== 1 || $commandUid <= 0) {
                continue;
            }
            $present = isset($known[$commandUid]);
            if ($command->getType() === 'info'
                && isset($preferredInfoByUid[$commandUid])
                && (int) $command->getId() !== $preferredInfoByUid[$commandUid]) {
                $present = false;
            }
            if ($command->getType() === 'action' && (string) $command->getConfiguration('operation', '') === 'write') {
                $present = $present && isset($writable[$commandUid]);
                if (isset($processedActionUids[$commandUid])
                    && !isset($expectedActionLogicalIds[(string) $command->getLogicalId()])) {
                    $present = false;
                }
            }
            $obsolescenceChange = self::setCommandObsolete($command, !$present);
            if ($obsolescenceChange > 0) {
                $markedObsolete++;
            } elseif ($obsolescenceChange < 0) {
                $restored++;
            }
        }
        if ($markedObsolete > 0 || $restored > 0) {
            log::add(__CLASS__, 'debug', sprintf(
                __('Synchronisation de %s : %d commande(s) devenue(s) obsolète(s), %d restaurée(s)', __FILE__),
                $eqLogic->getHumanName(),
                $markedObsolete,
                $restored
            ));
        }
    }

    /**
     * Masque les anciennes commandes numériques des racines de programme.
     *
     * Elles ont été remplacées par les commandes textuelles `active_program`
     * et `selected_program`, mais sont conservées pour ne casser aucun scénario.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @return void
     */
    private static function retireLegacyProgramRootCommands($eqLogic)
    {
        $labels = array(
            'BSH.Common.Root.ActiveProgram' => __('Identifiant du programme actif', __FILE__),
            'BSH.Common.Root.SelectedProgram' => __('Identifiant du programme sélectionné', __FILE__),
        );
        foreach ($eqLogic->getCmd('info') as $command) {
            $feature = (string) $command->getConfiguration('feature', '');
            if ((int) $command->getConfiguration('generated', 0) !== 1 || !isset($labels[$feature])) {
                continue;
            }
            $command->setName($labels[$feature])->setIsVisible(0)->setUnite('');
            self::saveCommandIfChanged($command);
        }
    }

    /**
     * Active ou retire proprement une commande issue du profil courant.
     *
     * Une commande absente du XML n'est jamais supprimée afin de préserver les
     * scénarios Jeedom. Elle est masquée et pourra être restaurée si une
     * réimportation de profil la réintroduit.
     *
     * @param localhomeconnectCmd $command Commande.
     * @param bool $obsolete État attendu.
     * @return int 1 si marquée obsolète, -1 si restaurée, 0 sinon.
     */
    private static function setCommandObsolete($command, $obsolete)
    {
        $currentlyObsolete = (int) $command->getConfiguration('obsolete', 0) === 1;
        $change = 0;
        if ($obsolete && !$currentlyObsolete) {
            $command->setConfiguration('visible_before_obsolete', (int) $command->getIsVisible());
            $command->setConfiguration('obsolete', 1);
            $command->setIsVisible(0);
            $change = 1;
        } elseif (!$obsolete && $currentlyObsolete) {
            $command->setConfiguration('obsolete', 0);
            $command->setIsVisible((int) $command->getConfiguration('visible_before_obsolete', 1));
            $change = -1;
        }
        self::saveCommandIfChanged($command);
        return $change;
    }

    /**
     * Sauvegarde une commande uniquement lorsqu'elle est nouvelle ou modifiée.
     *
     * @param localhomeconnectCmd $command Commande.
     * @return void
     */
    private static function saveCommandIfChanged($command)
    {
        if ((int) $command->getId() <= 0 || $command->getChanged()) {
            $command->save();
        }
    }

    /**
     * Construit une fois l'index des noms de commandes d'un équipement.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @return array<string,bool>
     */
    private static function commandNameIndex($eqLogic)
    {
        $index = array();
        foreach ((array) $eqLogic->getCmd() as $command) {
            if (is_object($command)) {
                $index[(string) $command->getName()] = true;
            }
        }
        return $index;
    }

    /**
     * Crée les actions adaptées à une capacité accessible en écriture.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @param localhomeconnectCmd $info Commande d'état.
     * @param array<string,mixed> $entity Entité.
     * @param array<string,string> $states Énumération.
     * @param array<string,int>|null $binary Correspondance binaire.
     * @param array<string,bool> $nameIndex Index partagé des noms de commandes.
     * @return string[] Logical IDs créés.
     */
    private static function syncEntityActions($eqLogic, $info, $entity, $states, $binary, &$nameIndex)
    {
        $uid = (int) ($entity['uidNumber'] ?? hexdec((string) $entity['uid']));
        $feature = (string) $entity['feature'];
        $created = array();
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : array();
        $protocolType = (string) ($metadata['protocolType'] ?? 'string');
        $requiresOptIn = !empty($entity['requiresOptIn']);
        if (!empty($entity['commandOnly']) || strpos($feature, '.Command.') !== false) {
            $logicalId = 'write_' . $uid;
            $label = LocalHomeConnectTranslator::feature($feature);
            $hasDefault = array_key_exists('default', $metadata) && $metadata['default'] !== '';
            $fixedValue = $hasDefault ? $metadata['default'] : ($protocolType === 'boolean' ? true : null);
            $subType = $fixedValue !== null ? 'other' : 'message';
            self::saveAction(
                $eqLogic,
                $logicalId,
                LocalHomeConnectTranslator::actionLabel($feature, $label, $subType, true),
                $subType,
                $uid,
                $fixedValue,
                $info,
                $nameIndex,
                $protocolType,
                $requiresOptIn
            );
            return array($logicalId);
        }
        if ($binary !== null) {
            $onRaw = array_search(1, $binary, true);
            $offRaw = array_search(0, $binary, true);
            foreach (array('on' => $onRaw, 'off' => $offRaw) as $suffix => $raw) {
                if ($raw === false) {
                    continue;
                }
                $logicalId = 'write_' . $uid . '_' . $suffix;
                self::saveAction(
                    $eqLogic,
                    $logicalId,
                    LocalHomeConnectTranslator::actionLabel($feature, $info->getName(), 'other', $suffix === 'on'),
                    'other',
                    $uid,
                    $raw,
                    $info,
                    $nameIndex,
                    $protocolType,
                    $requiresOptIn
                );
                $created[] = $logicalId;
            }
            return $created;
        }
        if (count($states) > 0) {
            $logicalId = 'write_' . $uid;
            $list = array();
            foreach ($states as $raw => $label) {
                $list[] = str_replace(array('|', ';'), '-', (string) $raw)
                    . '|' . str_replace(array('|', ';'), '-', (string) $label);
            }
            $label = LocalHomeConnectTranslator::actionLabel($feature, LocalHomeConnectTranslator::feature($feature), 'select');
            $action = self::saveAction($eqLogic, $logicalId, $label, 'select', $uid, null, $info, $nameIndex, $protocolType, $requiresOptIn);
            if ((int) $action->getConfiguration('manual_override', 0) !== 1) {
                $action->setConfiguration('listValue', implode(';', $list));
                self::saveCommandIfChanged($action);
            }
            return array($logicalId);
        }
        if ($info->getSubType() === 'numeric' && isset($entity['metadata']['min'], $entity['metadata']['max'])) {
            $logicalId = 'write_' . $uid;
            $label = LocalHomeConnectTranslator::actionLabel($feature, LocalHomeConnectTranslator::feature($feature), 'slider');
            $action = self::saveAction($eqLogic, $logicalId, $label, 'slider', $uid, null, $info, $nameIndex, $protocolType, $requiresOptIn);
            if ((int) $action->getConfiguration('manual_override', 0) !== 1) {
                $action->setConfiguration('minValue', (float) $entity['metadata']['min']);
                $action->setConfiguration('maxValue', (float) $entity['metadata']['max']);
                $action->setConfiguration('step', isset($entity['metadata']['step']) ? (float) $entity['metadata']['step'] : 1);
                $parameters = $action->getDisplay('parameters', array());
                $parameters['step'] = isset($entity['metadata']['step']) ? (float) $entity['metadata']['step'] : 1;
                $action->setDisplay('parameters', $parameters);
                self::saveCommandIfChanged($action);
            }
            return array($logicalId);
        }
        $logicalId = 'write_' . $uid;
        $label = LocalHomeConnectTranslator::actionLabel($feature, LocalHomeConnectTranslator::feature($feature), 'message');
        self::saveAction($eqLogic, $logicalId, $label, 'message', $uid, null, $info, $nameIndex, $protocolType, $requiresOptIn);
        return array($logicalId);
    }

    /**
     * Enregistre une action d'écriture et la relie à son information.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @param string $logicalId Identifiant.
     * @param string $name Nom.
     * @param string $subType Sous-type Jeedom.
     * @param int $uid UID Home Connect.
     * @param mixed $fixedValue Valeur fixe éventuelle.
     * @param localhomeconnectCmd $info Information associée.
     * @param array<string,bool> $nameIndex Index partagé des noms de commandes.
     * @param string $protocolType Type déclaré dans le profil XML.
     * @param bool $requiresOptIn Indique une écriture XML inconnue à activer manuellement.
     * @return localhomeconnectCmd
     */
    private static function saveAction($eqLogic, $logicalId, $name, $subType, $uid, $fixedValue, $info, &$nameIndex, $protocolType, $requiresOptIn = false)
    {
        $command = $eqLogic->getCmd('action', $logicalId);
        if (!is_object($command)) {
            $command = new localhomeconnectCmd();
            $command->setEqLogic_id($eqLogic->getId());
            $command->setLogicalId($logicalId);
        }
        if ((int) $command->getConfiguration('manual_override', 0) === 1) {
            if ((int) $command->getConfiguration('obsolete', 0) === 1) {
                $command->setConfiguration('obsolete', 0);
                $command->setIsVisible((int) $command->getConfiguration('visible_before_obsolete', 1));
                self::saveCommandIfChanged($command);
            }
            return $command;
        }
        $command->setName(self::uniqueCommandName($nameIndex, $command, $name, $uid));
        $command->setType('action');
        $command->setSubType($subType);
        $command->setIsVisible($requiresOptIn ? 0 : 1);
        $command->setConfiguration('operation', 'write');
        $command->setConfiguration('uid', $uid);
        $command->setConfiguration('generated', 1);
        $command->setConfiguration('obsolete', 0);
        $command->setConfiguration('has_fixed_value', $fixedValue !== null ? 1 : 0);
        $command->setConfiguration('protocol_value_type', $protocolType);
        $command->setConfiguration('requires_opt_in', $requiresOptIn ? 1 : 0);
        $command->setConfiguration('fixed_value', self::normalizeProtocolValue($fixedValue, $protocolType));
        $command->setConfiguration('feature', $info->getConfiguration('feature', ''));
        $command->setConfiguration('category', $info->getConfiguration('category', 'information'));
        $command->setUnite($subType === 'slider' ? $info->getUnite() : '');
        $command->setValue($info->getId());
        self::saveCommandIfChanged($command);
        return $command;
    }

    /**
     * Évite la collision Jeedom lorsque deux UIDs ont le même libellé.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @param localhomeconnectCmd $command Commande en cours.
     * @param string $name Nom proposé.
     * @param int $uid UID Home Connect.
     * @return string
     */
    private static function uniqueCommandName(&$nameIndex, $command, $name, $uid)
    {
        $name = trim((string) $name);
        $previousName = (string) $command->getName();
        if ($previousName !== '') {
            unset($nameIndex[$previousName]);
        }
        $candidate = $name;
        $suffix = 0;
        do {
            $collision = isset($nameIndex[$candidate]);
            if ($collision) {
                $suffix++;
                $candidate = $name . ' [' . strtoupper(dechex((int) $uid)) . ($suffix > 1 ? '-' . $suffix : '') . ']';
            }
        } while ($collision);
        $nameIndex[$candidate] = true;
        return $candidate;
    }

    /**
     * Rend à Home Connect le type JSON attendu par son profil.
     *
     * Les clés d'énumération proviennent du XML et sont donc initialement des
     * chaînes, même lorsqu'elles représentent un entier ou un booléen.
     *
     * @param mixed $value Valeur issue du profil ou du formulaire Jeedom.
     * @param string $protocolType Type déclaré dans le profil XML.
     * @return mixed
     */
    public static function normalizeProtocolValue($value, $protocolType = '')
    {
        $protocolType = strtolower(trim((string) $protocolType));
        if (!is_string($value) || $protocolType === '') {
            return $value;
        }
        $trimmed = trim($value);
        if ($protocolType === 'integer' && preg_match('/^-?\d+$/', $trimmed)) {
            return (int) $trimmed;
        }
        if (in_array($protocolType, array('number', 'float', 'double'), true) && is_numeric($trimmed)) {
            return (float) $trimmed;
        }
        if ($protocolType === 'boolean') {
            if (in_array(strtolower($trimmed), array('true', '1', 'on'), true)) {
                return true;
            }
            if (in_array(strtolower($trimmed), array('false', '0', 'off'), true)) {
                return false;
            }
        }
        return $value;
    }

    /**
     * Retourne le type JSON attendu à partir des métadonnées XML enrichies.
     *
     * Un type explicite du dictionnaire ne remplace que le repli « string »
     * du démon. Ainsi, une valeur textuelle telle que « 001 » reste intacte,
     * tandis qu'une commande déclarée Boolean reçoit bien un booléen JSON.
     *
     * @param array<string,mixed> $metadata Métadonnées de la capacité.
     * @return string Type protocolaire normalisé.
     */
    private static function protocolTypeFromMetadata($metadata)
    {
        $protocolType = strtolower(trim((string) ($metadata['protocolType'] ?? '')));
        if (!in_array($protocolType, array('boolean', 'integer', 'number', 'string'), true)) {
            $protocolType = 'string';
        }
        $declaredType = strtolower(trim((string) ($metadata['type'] ?? '')));
        $declaredTypes = array(
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'byte' => 'integer',
            'short' => 'integer',
            'int' => 'integer',
            'integer' => 'integer',
            'long' => 'integer',
            'float' => 'number',
            'double' => 'number',
            'decimal' => 'number',
            'number' => 'number',
            'string' => 'string',
        );
        if ($protocolType === 'string' && isset($declaredTypes[$declaredType])) {
            return $declaredTypes[$declaredType];
        }
        return $protocolType;
    }

    /**
     * Crée les commandes de sélection et de démarrage des programmes.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @param array<int,array<string,mixed>> $programs Programmes connus.
     * @param mixed $selected Programme sélectionné.
     * @param mixed $active Programme actif.
     * @param bool $canSelect L'appareil autorise la sélection.
     * @param bool $canStart L'appareil autorise le démarrage.
     * @return void
     */
    private static function syncPrograms($eqLogic, $programs, $selected, $active, $canSelect, $canStart)
    {
        $choices = array();
        $labels = array();
        foreach ($programs as $program) {
            if (!is_array($program) || !isset($program['uid'])) {
                continue;
            }
            $uid = (int) $program['uid'];
            $label = LocalHomeConnectTranslator::program((string) ($program['feature'] ?? $program['name'] ?? $uid));
            $choices[] = $uid . '|' . str_replace(array('|', ';'), '-', $label);
            $labels[$uid] = $label;
        }
        foreach (array('selected_program' => __('Programme sélectionné', __FILE__), 'active_program' => __('Programme actif', __FILE__)) as $logicalId => $name) {
            $command = $eqLogic->getCmd('info', $logicalId);
            if (!is_object($command)) {
                $command = new localhomeconnectCmd();
                $command->setEqLogic_id($eqLogic->getId());
                $command->setLogicalId($logicalId);
            }
            $visible = $logicalId !== 'selected_program' || !$canSelect;
            $command->setName($name)->setType('info')->setSubType('string')->setIsVisible($visible ? 1 : 0);
            $command->setConfiguration('category', 'program')->setConfiguration('generated', 1);
            self::saveCommandIfChanged($command);
        }
        $selectedUid = $eqLogic->getCmd('info', 'selected_program_uid');
        if (!is_object($selectedUid)) {
            $selectedUid = new localhomeconnectCmd();
            $selectedUid->setEqLogic_id($eqLogic->getId());
            $selectedUid->setLogicalId('selected_program_uid');
        }
        $selectedUid->setName(__('Identifiant du programme sélectionné', __FILE__))
            ->setType('info')->setSubType('numeric')->setIsVisible(0);
        $selectedUid->setConfiguration('category', 'program')->setConfiguration('generated', 1);
        self::saveCommandIfChanged($selectedUid);
        $eqLogic->checkAndUpdateCmd('selected_program', isset($labels[(int) $selected]) ? $labels[(int) $selected] : '');
        $eqLogic->checkAndUpdateCmd('active_program', isset($labels[(int) $active]) ? $labels[(int) $active] : '');
        $eqLogic->checkAndUpdateCmd('selected_program_uid', is_numeric($selected) ? (int) $selected : 0);
        if (count($choices) === 0) {
            foreach (array('select_program', 'start_program') as $logicalId) {
                $existing = $eqLogic->getCmd('action', $logicalId);
                if (is_object($existing)) {
                    $existing->setIsVisible(0);
                    self::saveCommandIfChanged($existing);
                }
            }
            return;
        }
        $select = $eqLogic->getCmd('action', 'select_program');
        if (!is_object($select)) {
            $select = new localhomeconnectCmd();
            $select->setEqLogic_id($eqLogic->getId())->setLogicalId('select_program');
        }
        $select->setName(__('Choisir le programme', __FILE__))->setType('action')->setSubType('select')->setIsVisible($canSelect ? 1 : 0);
        $select->setConfiguration('operation', 'select_program')->setConfiguration('generated', 1);
        $selectedInfo = $eqLogic->getCmd('info', 'selected_program_uid');
        if (is_object($selectedInfo)) {
            $select->setValue($selectedInfo->getId());
        }
        $select->setConfiguration('listValue', implode(';', $choices));
        self::saveCommandIfChanged($select);

        $start = $eqLogic->getCmd('action', 'start_program');
        if (!is_object($start)) {
            $start = new localhomeconnectCmd();
            $start->setEqLogic_id($eqLogic->getId())->setLogicalId('start_program');
        }
        $start->setName(__('Démarrer le programme sélectionné', __FILE__))->setType('action')->setSubType('other')->setIsVisible($canStart ? 1 : 0);
        $start->setConfiguration('operation', 'start_program')->setConfiguration('generated', 1);
        self::saveCommandIfChanged($start);
    }

    /**
     * Crée les commandes permanentes de l'équipement.
     *
     * @param localhomeconnect $eqLogic Équipement.
     * @return void
     */
    private static function ensureCoreCommands($eqLogic)
    {
        foreach (array('connected' => array(__('Connecté', __FILE__), 'binary')) as $logicalId => $definition) {
            $command = $eqLogic->getCmd('info', $logicalId);
            if (!is_object($command)) {
                $command = new localhomeconnectCmd();
                $command->setEqLogic_id($eqLogic->getId())->setLogicalId($logicalId);
            }
            $command->setName($definition[0])->setType('info')->setSubType($definition[1])->setIsVisible(0);
            self::saveCommandIfChanged($command);
        }
        foreach (array('refresh' => array(__('Rafraîchir', __FILE__), 'refresh')) as $logicalId => $definition) {
            $command = $eqLogic->getCmd('action', $logicalId);
            if (!is_object($command)) {
                $command = new localhomeconnectCmd();
                $command->setEqLogic_id($eqLogic->getId())->setLogicalId($logicalId);
            }
            $command->setName($definition[0])->setType('action')->setSubType('other');
            $command->setIsVisible(1);
            $command->setConfiguration('operation', $definition[1]);
            self::saveCommandIfChanged($command);
        }
        foreach (array('ready', 'connection_state', 'test_communication') as $legacyLogicalId) {
            $legacy = $eqLogic->getCmd(null, $legacyLogicalId);
            if (is_object($legacy)) {
                $legacy->setIsVisible(0)->setConfiguration('obsolete', 1);
                self::saveCommandIfChanged($legacy);
            }
        }
    }

    /**
     * Déduit une correspondance binaire depuis les libellés d'une énumération.
     *
     * @param array<string,string> $states États.
     * @return array<string,int>|null
     */
    private static function binaryStateMap($states)
    {
        if (count($states) !== 2) {
            return null;
        }
        $map = array();
        foreach ($states as $raw => $label) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $label));
            if (preg_match('/^(on|true|enabled|active|activated|closed|locked|yes|present|detected|allowed|available|marche|allume|activee)$/', $normalized)
                || preg_match('/on$/', $normalized)) {
                $map[(string) $raw] = 1;
            } elseif (preg_match('/^(off|false|disabled|inactive|deactivated|open|unlocked|no|notpresent|notdetected|notallowed|unavailable|notavailable|arret|eteint|desactivee)$/', $normalized)
                || preg_match('/off$/', $normalized)) {
                $map[(string) $raw] = 0;
            }
        }
        if (count($map) === 2 && count(array_unique($map)) === 2) {
            return $map;
        }
        return null;
    }

    /**
     * Indique si une mesure mérite une historisation par défaut.
     *
     * @param string $feature Fonction Home Connect.
     * @param array<string,mixed> $metadata Métadonnées.
     * @param string $subType Sous-type.
     * @return bool
     */
    private static function shouldHistorize($feature, $metadata, $subType)
    {
        if ($subType === 'binary') {
            return preg_match('/(?:PowerState|DoorState|RemoteControl|ProgramFinished|Error|Alarm)/i', $feature) === 1;
        }
        $unit = (string) ($metadata['unit'] ?? '');
        return $subType === 'numeric' && (
            in_array($unit, array('%', '°C', '°F', 'W', 'Wh', 'kWh', 's'), true)
            || preg_match('/(?:Progress|Temperature|Energy|Power|Remaining|Duration|Consumption)/i', $feature)
        );
    }

    /**
     * Normalise les unités fournies par les profils.
     *
     * @param string $unit Unité brute.
     * @return string
     */
    private static function normalizeUnit($unit)
    {
        $normalized = strtolower(trim($unit));
        $map = array(
            'seconds' => 's', 'second' => 's', 'sec' => 's',
            'minutes' => 'min', 'minute' => 'min',
            'hours' => 'h', 'hour' => 'h',
            'percent' => '%', 'percentage' => '%',
            'celsius' => '°C', 'degreecelsius' => '°C',
            'fahrenheit' => '°F', 'degreefahrenheit' => '°F',
            'watt' => 'W', 'watthour' => 'Wh', 'kilowatthour' => 'kWh',
            'liter' => 'l', 'litre' => 'l', 'milliliter' => 'ml', 'millilitre' => 'ml',
            'gram' => 'g', 'kilogram' => 'kg',
            'rpm' => 'tr/min', 'revolutionsperminute' => 'tr/min',
            'decibel' => 'dB', 'dbm' => 'dBm', 'hertz' => 'Hz',
        );
        return $map[$normalized] ?? trim($unit);
    }

    /**
     * Évite l'erreur SQL peu explicite produite par Jeedom lorsque deux
     * équipements portent le même nom dans le même objet parent.
     *
     * @return void
     */
    public function preSave()
    {
        $objectId = $this->getObject_id();
        $name = trim((string) $this->getName());
        if ($name === '' || $objectId === null || $objectId === '') {
            return;
        }
        $baseName = $name;
        $candidate = $baseName;
        $suffix = 1;
        while (self::equipmentNameExistsInObject($candidate, (int) $objectId, (int) $this->getId())) {
            $candidate = $baseName . ($suffix === 1 ? ' (LocalHomeConnect)' : ' (LocalHomeConnect ' . $suffix . ')');
            $suffix++;
        }
        if ($candidate !== $name) {
            log::add(__CLASS__, 'warning', sprintf(
                __('Le nom « %s » existe déjà dans cet objet ; l’équipement a été renommé « %s »', __FILE__),
                $name,
                $candidate
            ));
            $this->setName($candidate);
        }
    }

    /**
     * Recherche une collision de nom avec les équipements retournés par le
     * core Jeedom pour un objet parent.
     *
     * @param string $name Nom à contrôler.
     * @param int $objectId Identifiant de l'objet parent.
     * @param int $excludedId Équipement en cours de sauvegarde.
     * @return bool
     */
    private static function equipmentNameExistsInObject($name, $objectId, $excludedId)
    {
        $object = jeeObject::byId($objectId);
        if (!is_object($object)) {
            return false;
        }
        foreach ((array) eqLogic::byObjectNameEqLogicName($object->getName(), $name) as $equipment) {
            if (!is_object($equipment) || (int) $equipment->getId() === $excludedId) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Garantit la présence des commandes techniques après chaque sauvegarde.
     *
     * @return void
     */
    public function postSave()
    {
        self::ensureCoreCommands($this);
    }

    /**
     * Retourne le libellé traduit du type d'appareil.
     *
     * @return string
     */
    public function getDeviceTypeLabel()
    {
        return LocalHomeConnectTranslator::applianceType((string) $this->getConfiguration('device_type', ''));
    }

    /**
     * Retourne l'illustration correspondant à la famille de l'appareil.
     *
     * @return string Chemin web utilisable par Jeedom.
     */
    public function getPathLogo()
    {
        $type = preg_replace('/[^A-Za-z0-9]/', '', (string) $this->getConfiguration('device_type', ''));
        $path = __DIR__ . '/../config/images/' . $type . '.png';
        if ($type !== '' && is_file($path)) {
            return 'plugins/localhomeconnect/core/config/images/' . $type . '.png';
        }
        return 'plugins/localhomeconnect/plugin_info/localhomeconnect_icon.svg';
    }

    /**
     * Retourne les contrôles généraux de la page Santé.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function health()
    {
        $dependency = self::dependancy_info();
        $daemon = self::deamon_info();
        $profiles = self::profiles();
        $storage = self::privateStorageHealth();
        $dependenciesReady = (string) ($dependency['state'] ?? 'nok') === 'ok';
        $runtimeDevices = is_array($daemon['devices'] ?? null) ? $daemon['devices'] : array();
        $connectedDevices = count(array_filter($runtimeDevices, function ($device) {
            return is_array($device) && !empty($device['connected']);
        }));
        return array(
            array(
                'test' => __('Dépendances', __FILE__),
                'state' => $dependenciesReady,
                'result' => $dependenciesReady ? __('Installées', __FILE__) : __('Manquantes', __FILE__),
                'advice' => $dependenciesReady ? '' : __('Relancez l’installation des dépendances', __FILE__),
            ),
            array(
                'test' => __('Démon local', __FILE__),
                'state' => (string) ($daemon['state'] ?? 'nok') === 'ok',
                'result' => (string) ($daemon['state'] ?? 'nok') === 'ok'
                    ? sprintf(
                        __('Démarré — %d appareil(s) connecté(s)', __FILE__),
                        $connectedDevices
                    )
                    : __('Arrêté', __FILE__),
                'advice' => (string) ($daemon['launchable'] ?? 'nok') === 'ok' ? '' : (string) ($daemon['launchable_message'] ?? ''),
            ),
            array(
                'test' => __('Profils Home Connect', __FILE__),
                'state' => count($profiles) > 0,
                'result' => sprintf(__('%d profil(s)', __FILE__), count($profiles)),
                'advice' => count($profiles) > 0 ? '' : __('Importez un profil depuis la configuration du plugin', __FILE__),
            ),
            array(
                'test' => __('Stockage privé', __FILE__),
                'state' => $storage['state'],
                'result' => $storage['state'] ? __('Protégé', __FILE__) : __('Permissions à corriger', __FILE__),
                'advice' => $storage['message'],
            ),
        );
    }

    /**
     * Corrige les droits et le refus HTTP, puis signale les éventuels échecs.
     *
     * @return array{state:bool,message:string}
     */
    private static function privateStorageHealth()
    {
        return self::securePrivateStorage();
    }

    /**
     * Restitue le widget personnalisé ou délègue au core Jeedom.
     *
     * @param string $version dashboard ou mobile.
     * @return string
     */
    public function toHtml($_version = 'dashboard')
    {
        if ((int) $this->getDisplay('widgetTmpl', 1) !== 1) {
            return parent::toHtml($_version);
        }
        $version = jeedom::versionAlias($_version);
        if (!in_array($version, array('dashboard', 'mobile'), true)) {
            return parent::toHtml($_version);
        }
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $connected = $this->getCmd('info', 'connected');
        $isOnline = is_object($connected) && (int) $connected->execCmd() === 1;
        $replace['#online_class#'] = $isOnline ? '' : ' localhomeconnect-offline';
        $replace['#online_label#'] = $isOnline ? __('Prêt', __FILE__) : __('Hors ligne', __FILE__);
        $deviceType = strtolower((string) $this->getConfiguration('device_type', ''));
        if ($deviceType === 'cooktop') {
            $deviceType = 'hob';
        }
        $replace['#device_class#'] = preg_replace('/[^a-z0-9_-]/', '', $deviceType);
        $replace['#device_type#'] = htmlspecialchars($deviceType, ENT_QUOTES, 'UTF-8');
        $replace['#commands#'] = $this->renderWidgetCommands($version, $isOnline);
        $refresh = $this->getCmd('action', 'refresh');
        $replace['#refresh_id#'] = is_object($refresh) ? (int) $refresh->getId() : '';
        $replace['#connected_id#'] = is_object($connected) ? (int) $connected->getId() : '';
        $replace['#online#'] = $isOnline ? 'true' : 'false';
        $templateName = 'localhomeconnect.device.template';
        $template = getTemplate('core', $version, $templateName, __CLASS__);
        if (!is_string($template) || $template === '') {
            return parent::toHtml($_version);
        }
        return $this->postToHtml($version, translate::exec(
            template_replace($replace, $template),
            'plugins/localhomeconnect/core/template/' . $version . '/' . $templateName . '.html'
        ));
    }

    /**
     * Rend les commandes visibles par catégories pour le widget.
     *
     * @param string $version Version du widget.
     * @param bool $canExecute Appareil connecté et capable de recevoir une commande.
     * @return string
     */
    private function renderWidgetCommands($version, $canExecute)
    {
        $deviceType = strtolower((string) $this->getConfiguration('device_type', ''));
        if ($deviceType === 'cooktop') {
            $deviceType = 'hob';
        }
        if (in_array($deviceType, array('oven', 'hob'), true)) {
            return $this->renderCookingWidgetCommands($version, $canExecute, $deviceType);
        }
        $groups = array('principal' => array(), 'programmes' => array(), 'entretien' => array(), 'consommation' => array(), 'informations' => array());
        $commands = $this->getCmd();
        $controlledInfoIds = array();
        foreach ($commands as $candidate) {
            if ($candidate->getType() === 'action' && (int) $candidate->getValue() > 0) {
                $controlledInfoIds[(int) $candidate->getValue()] = true;
            }
        }
        $renderedToggles = array();
        foreach ($commands as $command) {
            if (!$command->getIsVisible() || in_array($command->getLogicalId(), array('refresh', 'connected', 'ready', 'connection_state'), true)) {
                continue;
            }
            if ($command->getType() === 'info' && isset($controlledInfoIds[(int) $command->getId()])) {
                continue;
            }
            $feature = (string) $command->getConfiguration('feature', '');
            $category = (string) $command->getConfiguration('category', '');
            $group = null;
            if (preg_match('/(?:Clean|Care|Filter|Salt|RinseAid|Error|Alarm|Reminder|WaterTank|DripTray)/i', $feature)) {
                $group = 'entretien';
            } elseif (preg_match('/(?:OperationState|DoorState|ProgramProgress|RemainingProgramTime|PowerState)/i', $feature)) {
                $group = 'principal';
            } elseif (preg_match('/(?:Energy|PowerConsumption|CurrentPower|WaterForecast|Consumption)/i', $feature)) {
                $group = 'consommation';
            } elseif (
                $command->getLogicalId() === 'select_program'
                || $command->getLogicalId() === 'start_program'
                || in_array($category, array('program', 'programs', 'options', 'phases', 'commands'), true)
            ) {
                $group = 'programmes';
            } elseif (preg_match('/(?:RemoteControl|LocalControl|WiFiSignal|Temperature|FillLevel|LoadRecommendation|Reload|BeanContainer)/i', $feature)) {
                $group = 'informations';
            } elseif (
                $command->getType() === 'action'
                && preg_match('/(?:Light|Lighting|Illumination|ChildLock|SuperMode|Vacation|EcoMode|Sabbath|Dispenser|CupWarmer|Ventilation|Boost|Fan)/i', $feature)
            ) {
                $group = 'principal';
            }
            if ($group === null) {
                continue;
            }
            $content = $this->renderWidgetCommandCard($command, $version, $canExecute, $group, $renderedToggles);
            if ($content !== '') {
                $groups[$group][] = $content;
            }
        }
        $labels = array(
            'principal' => array('fas fa-home', __('Principal', __FILE__)),
            'programmes' => array('fas fa-play-circle', __('Programmes', __FILE__)),
            'entretien' => array('fas fa-tools', __('Entretien', __FILE__)),
            'consommation' => array('fas fa-bolt', __('Consommation', __FILE__)),
            'informations' => array('fas fa-info-circle', __('Informations', __FILE__)),
        );
        $navigation = '<div class="localhomeconnect-tabs" role="tablist">';
        $pages = '';
        $first = true;
        foreach ($groups as $key => $commands) {
            if (count($commands) === 0) {
                continue;
            }
            $active = $first ? ' active' : '';
            $navigation .= '<button type="button" class="localhomeconnect-tab' . $active . '" data-page="' . $key . '"><i class="' . $labels[$key][0] . '"></i> ' . $labels[$key][1] . '</button>';
            $pages .= '<section class="localhomeconnect-page' . $active . '" data-page="' . $key . '">' . implode('', $commands) . '</section>';
            $first = false;
        }
        return $navigation . '</div>' . $pages;
    }

    /**
     * Rend un widget métier pour un four ou une table de cuisson.
     *
     * @param string $version Version Jeedom.
     * @param bool $canExecute Appareil connecté et capable de recevoir une commande.
     * @param string $deviceType Type `oven` ou `hob`.
     * @return string
     */
    private function renderCookingWidgetCommands($version, $canExecute, $deviceType)
    {
        $isHob = $deviceType === 'hob';
        $groups = $isHob
            ? array('foyers' => array(), 'programmes' => array(), 'ventilation' => array(), 'reglages' => array(), 'entretien' => array(), 'consommation' => array())
            : array('principal' => array(), 'reglages' => array(), 'entretien' => array(), 'consommation' => array());
        $controlledInfoIds = array();
        foreach ($this->getCmd('action') as $action) {
            if ((int) $action->getValue() > 0) {
                $controlledInfoIds[(int) $action->getValue()] = true;
            }
        }
        $renderedToggles = array();
        $zones = array();
        foreach ($this->getCmd() as $command) {
            $feature = (string) $command->getConfiguration('feature', '');
            $semanticName = $feature . ' ' . (string) $command->getName();
            $forceOvenCookingAction = !$isHob
                && $command->getType() === 'action'
                && (int) $command->getConfiguration('obsolete', 0) !== 1
                && (
                    in_array($command->getLogicalId(), array('select_program', 'start_program'), true)
                    || preg_match('/(?:Program|Programme|Temperature|Température|Setpoint|Timer|Minuteur|Duration|Durée|AlarmClock|StartIn|FinishIn)/iu', $semanticName)
                );
            if ((!$command->getIsVisible() && !$forceOvenCookingAction) || in_array($command->getLogicalId(), array(
                'refresh', 'test_communication', 'connected', 'ready', 'connection_state',
                'selected_program_uid'
            ), true)) {
                continue;
            }
            $category = (string) $command->getConfiguration('category', '');
            if ($isHob && $command->getType() === 'info'
                && preg_match('/Cooking\.Hob\.Status\.Zone\.(\d+)\.([^.]+)$/i', $feature, $matches)) {
                $zones[$matches[1]][$matches[2]] = $command;
                continue;
            }
            if ($command->getType() === 'info' && isset($controlledInfoIds[(int) $command->getId()])) {
                continue;
            }

            $group = '';
            $isMaintenance = preg_match(
                '/(?:Error|Overheat|TemperatureTooHigh|MaxSaturation|Filter|Clean|Care|Alarm|CustomerServiceRequest|ConfirmAction|WaterTank|UserInteractionRequired)/i',
                $feature
            ) === 1;
            $isConsumption = preg_match('/(?:CurrentPower|PowerConsumption|EnergyConsumption|ConsumptionForecast|WaterForecast)/i', $feature) === 1
                && stripos($feature, 'EnergyConsumptionIndication') === false;
            $isProgram = in_array($command->getLogicalId(), array('select_program', 'start_program', 'selected_program', 'active_program'), true)
                || in_array($category, array('program', 'programs', 'options', 'phases'), true);
            if ($isMaintenance) {
                $group = 'entretien';
            } elseif ($isConsumption) {
                $group = 'consommation';
            } elseif ($isHob && preg_match('/(?:Ventilation|AirCirculation|AirQualitySensor)/i', $feature)) {
                $group = 'ventilation';
            } elseif (!$isHob && ($isProgram || preg_match('/(?:OperationState|DoorState|Temperature|CavityHeatup|Preheat|ProgramProgress|RemainingProgramTime|ElapsedProgramTime|ProgramPhase|ProcessPhase|PowerState|Timer|Duration|AlarmClock)/i', $feature))) {
                $group = 'principal';
            } elseif (!$isHob && $command->getLogicalId() === 'active_program') {
                $group = 'principal';
            } elseif ($isHob && $isProgram) {
                $group = 'programmes';
            } elseif ($isHob && preg_match('/BSH\.Common\.Status\.OperationState$/i', $feature)) {
                $group = 'foyers';
            } elseif (preg_match('/(?:RemoteControl|LocalControl|ChildLock|Sabbath|Light|Illumination|ButtonTone|Clock|Display|SignalDuration|StopWatch|WipeProtection)/i', $feature)) {
                $group = 'reglages';
            } elseif ($command->getType() === 'action' || in_array($category, array('settings', 'commands'), true)) {
                $group = 'reglages';
            }
            if ($group === '' || !array_key_exists($group, $groups)) {
                continue;
            }
            $card = $this->renderWidgetCommandCard($command, $version, $canExecute, $group, $renderedToggles, ' localhomeconnect-cooking-command');
            if ($card !== '') {
                if (!$isHob && $group === 'principal') {
                    $groups[$group][] = array(
                        'priority' => self::ovenCookingCommandPriority($command),
                        'html' => $card,
                    );
                } else {
                    $groups[$group][] = $card;
                }
            }
        }

        if (!$isHob && count($groups['principal']) > 0) {
            usort($groups['principal'], function ($left, $right) {
                return (int) $left['priority'] <=> (int) $right['priority'];
            });
            $groups['principal'] = array_map(function ($entry) {
                return (string) $entry['html'];
            }, $groups['principal']);
        }

        if ($isHob) {
            $order = array('100', '200', '300', '400', '120', '340');
            foreach ($order as $zoneId) {
                if (!isset($zones[$zoneId])) {
                    continue;
                }
                $groups['foyers'][] = $this->renderHobZoneCard($zoneId, $zones[$zoneId]);
            }
        }

        $labels = $isHob ? array(
            'foyers' => array('fas fa-fire-alt', __('Foyers', __FILE__)),
            'programmes' => array('fas fa-utensils', __('Programmes', __FILE__)),
            'ventilation' => array('fas fa-fan', __('Ventilation', __FILE__)),
            'reglages' => array('fas fa-sliders-h', __('Réglages', __FILE__)),
            'entretien' => array('fas fa-exclamation-triangle', __('Entretien', __FILE__)),
            'consommation' => array('fas fa-bolt', __('Consommation', __FILE__)),
        ) : array(
            'principal' => array('fas fa-temperature-high', __('Cuisson', __FILE__)),
            'reglages' => array('fas fa-sliders-h', __('Réglages', __FILE__)),
            'entretien' => array('fas fa-exclamation-triangle', __('Entretien', __FILE__)),
            'consommation' => array('fas fa-bolt', __('Consommation', __FILE__)),
        );
        return self::renderWidgetPages($groups, $labels);
    }

    /**
     * Place les commandes nécessaires au lancement d'une cuisson avant les
     * informations de suivi, sans dépendre de l'ordre des commandes Jeedom.
     *
     * @param cmd $command Commande à ordonner.
     * @return int
     */
    private static function ovenCookingCommandPriority($command)
    {
        $logicalId = (string) $command->getLogicalId();
        $semanticName = (string) $command->getConfiguration('feature', '') . ' ' . (string) $command->getName();
        if ($logicalId === 'select_program') {
            return 10;
        }
        if ($command->getType() === 'action' && preg_match('/(?:Temperature|Température|Setpoint)/iu', $semanticName)) {
            return 20;
        }
        if ($command->getType() === 'action' && preg_match('/(?:Timer|Minuteur|Duration|Durée|AlarmClock|StartIn|FinishIn)/iu', $semanticName)) {
            return 30;
        }
        if ($logicalId === 'start_program') {
            return 40;
        }
        if (preg_match('/(?:OperationState|ActiveProgram|Programme actif)/iu', $semanticName)) {
            return 50;
        }
        if (preg_match('/(?:Remaining|Elapsed|Progress|Temps restant|Progression)/iu', $semanticName)) {
            return 60;
        }
        return 100;
    }

    /**
     * Rend une commande dans une carte homogène.
     *
     * @param cmd $command Commande Jeedom.
     * @param string $version Version du widget.
     * @param bool $canExecute Appareil connecté.
     * @param string $group Groupe visuel.
     * @param array<string,bool> $renderedToggles Toggles déjà rendus.
     * @param string $extraClass Classe complémentaire.
     * @return string
     */
    private function renderWidgetCommandCard($command, $version, $canExecute, $group, &$renderedToggles, $extraClass = '')
    {
        $feature = (string) $command->getConfiguration('feature', '');
        $historyClass = $command->getType() === 'info' && $command->getSubType() === 'numeric' && $command->getIsHistorized()
            ? ' cmd history cursor' : '';
        $numericClass = $command->getType() === 'info' && $command->getSubType() === 'numeric'
            ? ' localhomeconnect-widget-command-numeric localhomeconnect-widget-command-presented' : '';
        $disabled = !$canExecute && $command->getType() === 'action' ? ' localhomeconnect-command-disabled' : '';
        $commandHtml = '';
        if ($command->getType() === 'action' && preg_match('/^write_(\d+)_(on|off)$/', $command->getLogicalId(), $matches)) {
            $toggleKey = $matches[1];
            if (isset($renderedToggles[$toggleKey])) {
                return '';
            }
            $on = $this->getCmd('action', 'write_' . $toggleKey . '_on');
            $off = $this->getCmd('action', 'write_' . $toggleKey . '_off');
            if (is_object($on) && is_object($off)) {
                $state = cmd::byId((int) $on->getValue());
                $checked = is_object($state) && (int) $state->execCmd() === 1;
                $label = is_object($state) ? $state->getName() : __('Activation', __FILE__);
                $commandHtml = '<label class="localhomeconnect-switch">'
                    . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
                    . '<input type="checkbox" class="localhomeconnect-toggle" role="switch"'
                    . ' data-on-cmd_id="' . (int) $on->getId() . '" data-off-cmd_id="' . (int) $off->getId() . '"'
                    . ' data-state-cmd_id="' . (is_object($state) ? (int) $state->getId() : '') . '"'
                    . ($checked ? ' checked' : '') . '><span class="localhomeconnect-switch-track"><i></i></span></label>';
                $renderedToggles[$toggleKey] = true;
            }
        }
        if ($commandHtml === '') {
            $commandHtml = $command->toHtml($version);
        }
        if (!is_string($commandHtml) || $commandHtml === '') {
            return '';
        }
        $percentage = '';
        if ($command->getType() === 'info' && $command->getSubType() === 'numeric' && $command->getUnite() === '%') {
            $numericValue = max(0, min(100, (float) $command->execCmd()));
            $percentage = '<div class="localhomeconnect-progress" data-progress-cmd_id="' . (int) $command->getId()
                . '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'
                . $numericValue . '"><span style="width:' . $numericValue . '%"></span></div>';
        }
        $icon = self::widgetIcon($feature, $group, $command->getType());
        $liveAttributes = ' data-feature="' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . '"';
        if ($command->getType() === 'info') {
            $liveAttributes .= ' data-current-value="' . htmlspecialchars((string) $command->execCmd(), ENT_QUOTES, 'UTF-8') . '"';
        }
        return '<div class="localhomeconnect-widget-command localhomeconnect-command-' . $command->getType()
            . $historyClass . $numericClass . $disabled . $extraClass . '" data-cmd_id="' . (int) $command->getId() . '"' . $liveAttributes . '>'
            . '<span class="localhomeconnect-command-icon"><i class="' . $icon . '"></i></span>'
            . '<div class="localhomeconnect-command-content">' . $commandHtml . $percentage . '</div></div>';
    }

    /**
     * Regroupe les informations d'un foyer dans une carte synthétique.
     *
     * @param string $zoneId Identifiant fonctionnel du foyer.
     * @param array<string,cmd> $commands Commandes de la zone.
     * @return string
     */
    private function renderHobZoneCard($zoneId, $commands)
    {
        $reference = reset($commands);
        $translated = is_object($reference)
            ? LocalHomeConnectTranslator::feature((string) $reference->getConfiguration('feature', ''))
            : sprintf(__('Zone %s', __FILE__), $zoneId);
        $parts = explode(' — ', $translated);
        $zoneName = count($parts) > 1 ? end($parts) : sprintf(__('Zone %s', __FILE__), $zoneId);
        $state = $commands['State'] ?? ($commands['OperationState'] ?? null);
        $power = $commands['PowerLevel'] ?? null;
        $program = $commands['ActiveProgram'] ?? null;
        $remaining = $commands['RemainingProgramTime'] ?? null;
        $progress = $commands['ProgramProgress'] ?? null;
        $sensor = $commands['CookingSensorLevel'] ?? ($commands['FryingSensorLevel'] ?? null);
        $stateValue = is_object($state) ? (string) $state->execCmd() : '';
        $powerValue = is_object($power) ? (string) $power->execCmd() : '';
        $combined = strtolower($stateValue . ' ' . $powerValue);
        $residual = preg_match('/(?:résid|resid|chaleur|heat)/i', $combined) === 1;
        $active = !$residual && preg_match('/(?:actif|active|run|cours|niveau|maintien|boost|intensif)/i', $combined) === 1;
        $isFlexible = in_array((string) $zoneId, array('120', '340'), true);
        $classes = 'localhomeconnect-zone-card' . ($active ? ' is-active' : ' is-idle')
            . ($residual ? ' has-residual-heat' : '') . ($isFlexible ? ' is-flex-zone' : '');
        $html = '<article class="' . $classes . '" data-zone-id="' . htmlspecialchars((string) $zoneId, ENT_QUOTES, 'UTF-8')
            . '" data-flex-zone="' . ($isFlexible ? '1' : '0') . '">';
        $html .= '<header><span class="localhomeconnect-zone-icon"><i class="fas fa-fire-alt"></i></span>'
            . '<strong>' . htmlspecialchars($zoneName, ENT_QUOTES, 'UTF-8') . '</strong>'
            . self::renderLiveWidgetValue($state, 'state', false) . '</header>';
        $html .= '<div class="localhomeconnect-zone-power">' . self::renderLiveWidgetValue($power, 'power', true) . '</div>';
        $html .= '<div class="localhomeconnect-zone-details">';
        if (is_object($program)) {
            $html .= '<div class="localhomeconnect-zone-program"><i class="fas fa-utensils"></i>'
                . self::renderLiveWidgetValue($program, 'program', false) . '</div>';
        }
        $html .= '<div class="localhomeconnect-zone-metrics">';
        if (is_object($remaining)) {
            $html .= '<span><i class="fas fa-hourglass-half"></i>' . self::renderLiveWidgetValue($remaining, 'remaining', true) . '</span>';
        }
        if (is_object($sensor)) {
            $html .= '<span><i class="fas fa-thermometer-half"></i>' . self::renderLiveWidgetValue($sensor, 'sensor', false) . '</span>';
        }
        $html .= '</div>';
        if (is_object($progress)) {
            $value = max(0, min(100, (float) $progress->execCmd()));
            $html .= '<div class="localhomeconnect-zone-progress-label"><span>' . __('Progression', __FILE__) . '</span>'
                . self::renderLiveWidgetValue($progress, 'progress', true) . '</div>'
                . '<div class="localhomeconnect-progress" data-progress-cmd_id="' . (int) $progress->getId()
                . '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $value
                . '"><span style="width:' . $value . '%"></span></div>';
        }
        $html .= '</div></article>';
        return $html;
    }

    /**
     * Rend une valeur légère actualisable sans dupliquer le widget du core.
     *
     * @param cmd|null $command Commande d'information.
     * @param string $role Rôle dans la carte.
     * @param bool $history Autorise l'ouverture de l'historique.
     * @return string
     */
    private static function renderLiveWidgetValue($command, $role, $history)
    {
        if (!is_object($command)) {
            return '<span class="localhomeconnect-live-value" data-zone-role="' . $role . '">—</span>';
        }
        $value = (string) $command->execCmd();
        $unit = trim((string) $command->getUnite());
        $display = trim($value) === '' ? '—' : $value;
        if ($unit !== '' && stripos($display, $unit) === false) {
            $display .= ' ' . $unit;
        }
        $historyClass = $history && $command->getSubType() === 'numeric' && $command->getIsHistorized()
            ? ' cmd history cursor' : '';
        return '<span class="localhomeconnect-live-value' . $historyClass . '" data-zone-role="' . $role
            . '" data-lhc-live-cmd="' . (int) $command->getId() . '" data-cmd_id="' . (int) $command->getId()
            . '" data-unit="' . htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') . '" data-current-value="'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /**
     * Assemble les onglets et masque automatiquement les pages vides.
     *
     * @param array<string,array<int,string>> $groups Cartes par page.
     * @param array<string,array<int,string>> $labels Icône et libellé.
     * @return string
     */
    private static function renderWidgetPages($groups, $labels)
    {
        $navigation = '<div class="localhomeconnect-tabs localhomeconnect-cooking-tabs" role="tablist">';
        $pages = '';
        $first = true;
        foreach ($groups as $key => $cards) {
            $cards = array_values(array_filter($cards, 'strlen'));
            if (count($cards) === 0 || !isset($labels[$key])) {
                continue;
            }
            $active = $first ? ' active' : '';
            $navigation .= '<button type="button" class="localhomeconnect-tab' . $active . '" data-page="' . $key . '"><i class="'
                . $labels[$key][0] . '"></i> ' . $labels[$key][1] . '</button>';
            $pages .= '<section class="localhomeconnect-page localhomeconnect-cooking-page' . $active . '" data-page="' . $key . '">'
                . implode('', $cards) . '</section>';
            $first = false;
        }
        return $navigation . '</div>' . $pages;
    }

    /**
     * Choisit une icône générique sans dépendre du modèle de l'appareil.
     *
     * @param string $feature Fonction Home Connect.
     * @param string $group Page du widget.
     * @param string $type Type de commande Jeedom.
     * @return string Classe Font Awesome.
     */
    private static function widgetIcon($feature, $group, $type)
    {
        $icons = array(
            '/Temperature|Heat|PreHeat/i' => 'fas fa-thermometer-half',
            '/PowerLevel|FryingSensor|CookingSensor/i' => 'fas fa-fire-alt',
            '/Light|Lighting|Illumination/i' => 'fas fa-lightbulb',
            '/Ventilation|Fan|AirCirculation/i' => 'fas fa-fan',
            '/Spin/i' => 'fas fa-sync-alt',
            '/ChildLock|KeyLock|Lock/i' => 'fas fa-lock',
            '/Door/i' => 'fas fa-door-closed',
            '/Progress|Phase/i' => 'fas fa-tasks',
            '/Remaining|Duration|FinishIn|StartIn/i' => 'fas fa-hourglass-half',
            '/Water|Rinse/i' => 'fas fa-tint',
            '/WiFiSignal/i' => 'fas fa-wifi',
            '/Energy|Power|Consumption/i' => 'fas fa-bolt',
            '/Clean|Care|Filter/i' => 'fas fa-broom',
            '/Error|Alarm|Reminder|Lack/i' => 'fas fa-exclamation-triangle',
            '/Program/i' => 'fas fa-play-circle',
        );
        foreach ($icons as $pattern => $icon) {
            if (preg_match($pattern, $feature)) {
                return $icon;
            }
        }
        if ($type === 'action') {
            return 'fas fa-sliders-h';
        }
        return $group === 'principal' ? 'fas fa-info-circle' : 'fas fa-circle';
    }
}

/**
 * Exécute les actions LocalHomeConnect et représente ses informations.
 */
class localhomeconnectCmd extends cmd
{
    /**
     * Envoie une action au démon local.
     *
     * @param array<string,mixed> $_options Valeurs fournies par Jeedom.
     * @return mixed
     */
    public function execute($_options = array())
    {
        if ($this->getType() !== 'action') {
            return null;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            throw new RuntimeException(__('Équipement introuvable', __FILE__));
        }
        $operation = (string) $this->getConfiguration('operation', '');
        if ($operation === 'refresh') {
            return localhomeconnect::refreshEquipment($eqLogic->getId());
        }
        $payload = array(
            'haId' => $eqLogic->getConfiguration('ha_id'),
            'type' => $operation,
        );
        if ($operation === 'write') {
            $payload['uid'] = (int) $this->getConfiguration('uid');
            $payload['value'] = LocalHomeConnectActionValidator::writeValue($this, $_options);
            $payload['allowUnknown'] = (int) $this->getConfiguration('requires_opt_in', 0) === 1
                && (int) $this->getConfiguration('manual_override', 0) === 1;
        } elseif ($operation === 'select_program') {
            $payload['program'] = LocalHomeConnectActionValidator::program($this, $_options);
        } elseif ($operation === 'start_program') {
            $payload['program'] = null;
        } else {
            throw new RuntimeException(__('Action LocalHomeConnect invalide', __FILE__));
        }
        if (array_key_exists('value', $payload)) {
            $payload['value'] = localhomeconnect::normalizeProtocolValue(
                $payload['value'],
                (string) $this->getConfiguration('protocol_value_type', 'string')
            );
        }
        // Le démon maintient la socket locale et constitue la source fiable
        // de l'état de connexion. Une information Jeedom peut être en retard
        // de quelques secondes et ne doit pas bloquer une action valide.
        $daemonStarted = localhomeconnect::ensureDaemonRunning();
        if ($daemonStarted) {
            localhomeconnect::refreshEquipment($eqLogic->getId());
        }
        $result = localhomeconnect::daemonRequestWithRecovery('command', $payload, 'POST', 60);
        if (is_array($result['health'] ?? null)) {
            localhomeconnect::applyConnectionStatus($eqLogic, $result['health']);
        } else {
            $eqLogic->checkAndUpdateCmd('connected', 1);
            $eqLogic->setStatus('lastCommunication', date('Y-m-d H:i:s'));
            $eqLogic->setConfiguration('last_error', '');
            if ($eqLogic->getChanged()) {
                $eqLogic->save(true);
            }
        }
        return $result;
    }
}
