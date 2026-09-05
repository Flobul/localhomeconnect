<?php

/**
 * Importe et conserve les profils privés Home Connect.
 *
 * Les secrets ne sont jamais stockés dans les équipements Jeedom. Chaque
 * profil est normalisé dans un répertoire privé identifié par un condensat du
 * haId de l'appareil.
 */
class LocalHomeConnectProfileStore
{
    private const PROFILE_SCHEMA_VERSION = 1;
    private const MAX_ARCHIVE_SIZE = 20971520;
    private const MAX_ENTRY_COUNT = 250;
    private const MAX_UNCOMPRESSED_SIZE = 52428800;

    /** @var string Répertoire racine privé des profils. */
    private $root;

    /** @var callable|null Journaliseur injecté. */
    private $logger;

    /** @var resource|null Descripteur conservant le verrou réentrant du magasin. */
    private $lockHandle = null;

    /** @var int Profondeur du verrou réentrant du magasin. */
    private $lockDepth = 0;

    /**
     * Initialise le magasin de profils.
     *
     * @param string $root Répertoire privé.
     * @param callable|null $logger Journaliseur facultatif.
     */
    public function __construct($root, $logger = null)
    {
        $this->root = rtrim((string) $root, '/');
        $this->logger = is_callable($logger) ? $logger : null;
        if ($this->root === '') {
            throw new InvalidArgumentException(__('Répertoire de profils invalide', __FILE__));
        }
        $this->ensureDirectory($this->root);
        $this->withStoreLock(function () {
            $this->cleanupInterruptedInstalls();
        });
    }

    /**
     * Importe tous les appareils valides présents dans un ZIP de profils.
     *
     * @param string $archivePath Chemin du fichier temporaire envoyé.
     * @return array<int,array<string,mixed>> Profils normalisés.
     */
    public function importZip($archivePath)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(__('L’extension PHP ZIP est nécessaire', __FILE__));
        }
        $archivePath = (string) $archivePath;
        if (!is_file($archivePath) || filesize($archivePath) <= 0) {
            throw new InvalidArgumentException(__('Le fichier de profil est vide ou introuvable', __FILE__));
        }
        if (filesize($archivePath) > self::MAX_ARCHIVE_SIZE) {
            throw new InvalidArgumentException(__('Le fichier de profil dépasse 20 Mo', __FILE__));
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new InvalidArgumentException(__('Le fichier transmis n’est pas une archive ZIP valide', __FILE__));
        }
        try {
            $this->validateArchive($zip);
            $profiles = array();
            $errors = array();
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if (!preg_match('/\.json$/i', $name)) {
                    continue;
                }
                $raw = $zip->getFromIndex($index);
                $metadata = json_decode((string) $raw, true);
                if (!is_array($metadata) || !isset($metadata['haId'], $metadata['key'])) {
                    continue;
                }
                try {
                    $profile = $this->importProfile($zip, $metadata, $name);
                    $profiles[$profile['profileId']] = $profile;
                } catch (Throwable $exception) {
                    $errors[] = basename($name) . ' : ' . $exception->getMessage();
                    $this->log('warning', end($errors));
                }
            }
            if (count($profiles) === 0) {
                throw new InvalidArgumentException(
                    __('Aucun profil Home Connect complet n’a été trouvé dans cette archive', __FILE__)
                    . (count($errors) > 0 ? ' — ' . implode(' ; ', array_slice($errors, 0, 3)) : '')
                );
            }
            return array_values($profiles);
        } finally {
            $zip->close();
        }
    }

    /**
     * Retourne la liste publique des profils installés.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listProfiles()
    {
        return $this->withStoreLock(function () {
            return $this->listProfilesLocked();
        }, LOCK_SH);
    }

    /**
     * Liste les profils alors que le verrou du magasin est détenu.
     *
     * @return array<int,array<string,mixed>>
     */
    private function listProfilesLocked()
    {
        $profiles = array();
        foreach ((array) glob($this->root . '/*/device.json') as $file) {
            $profile = json_decode((string) file_get_contents($file), true);
            if (!is_array($profile) || empty($profile['haId'])) {
                continue;
            }
            unset($profile['key'], $profile['iv']);
            $profile['profileId'] = basename(dirname($file));
            $profiles[] = $profile;
        }
        usort($profiles, function ($left, $right) {
            return strnatcasecmp(
                (string) ($left['name'] ?? $left['haId']),
                (string) ($right['name'] ?? $right['haId'])
            );
        });
        return $profiles;
    }

    /**
     * Charge un profil complet destiné au démon.
     *
     * @param string $profileId Identifiant interne.
     * @return array<string,mixed>
     */
    public function getProfile($profileId)
    {
        return $this->withStoreLock(function () use ($profileId) {
            return $this->getProfileLocked($profileId);
        });
    }

    /**
     * Charge un profil alors que le verrou exclusif est détenu.
     *
     * @param string $profileId Identifiant interne.
     * @return array<string,mixed>
     */
    private function getProfileLocked($profileId)
    {
        $file = $this->getProfilePathLocked($profileId);
        $profileId = basename(dirname($file));
        $directory = dirname($file);
        if (!is_file($file)) {
            throw new RuntimeException(__('Profil Home Connect introuvable', __FILE__));
        }
        $profile = json_decode((string) file_get_contents($file), true);
        if (!is_array($profile)) {
            throw new RuntimeException(__('Profil Home Connect corrompu', __FILE__));
        }
        $schemaVersion = isset($profile['profileSchemaVersion'])
            ? (int) $profile['profileSchemaVersion']
            : 0;
        if ($schemaVersion > self::PROFILE_SCHEMA_VERSION) {
            throw new RuntimeException(__('Ce profil a été créé par une version plus récente du plugin', __FILE__));
        }
        if ($schemaVersion < self::PROFILE_SCHEMA_VERSION) {
            $profile['profileSchemaVersion'] = self::PROFILE_SCHEMA_VERSION;
            $encoded = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) {
                throw new RuntimeException(__('Migration du profil Home Connect impossible', __FILE__));
            }
            $this->atomicWrite($file, $encoded . "\n");
        }
        $profile['profileId'] = $profileId;
        $profile['deviceDescriptionPath'] = $directory . '/DeviceDescription.xml';
        $profile['featureMappingPath'] = $directory . '/FeatureMapping.xml';
        return $profile;
    }

    /**
     * Retourne le fichier privé d'un profil sans en recopier les secrets.
     *
     * @param string $profileId Identifiant interne.
     * @return string
     */
    public function getProfilePath($profileId)
    {
        return $this->withStoreLock(function () use ($profileId) {
            return $this->getProfilePathLocked($profileId);
        });
    }

    /**
     * Résout le chemin d'un profil alors que le verrou est détenu.
     *
     * @param string $profileId Identifiant interne.
     * @return string
     */
    private function getProfilePathLocked($profileId)
    {
        $profileId = strtolower(trim((string) $profileId));
        if (!preg_match('/^[a-f0-9]{24}$/', $profileId)) {
            throw new InvalidArgumentException(__('Identifiant de profil invalide', __FILE__));
        }
        $file = $this->root . '/' . $profileId . '/device.json';
        if (!is_file($file)) {
            $backups = (array) glob($this->root . '/.' . $profileId . '.backup-*/device.json');
            rsort($backups, SORT_STRING);
            if (isset($backups[0])) {
                @rename(dirname($backups[0]), $this->root . '/' . $profileId);
            }
        }
        if (!is_file($file)) {
            throw new RuntimeException(__('Profil Home Connect introuvable', __FILE__));
        }
        return $file;
    }

    /**
     * Supprime un profil privé après validation de son identifiant.
     *
     * L'appelant doit vérifier au préalable qu'aucun équipement ne l'utilise.
     *
     * @param string $profileId Identifiant interne.
     * @return void
     */
    public function deleteProfile($profileId)
    {
        $this->withStoreLock(function () use ($profileId) {
            $directory = dirname($this->getProfilePathLocked($profileId));
            $this->removeDirectory($directory);
        });
    }

    /**
     * Relit dans les XML installés la définition d'une commande Home Connect.
     *
     * Les informations retournées sont destinées à l'interface de diagnostic :
     * elles ne contiennent ni clé de chiffrement ni chemin absolu du serveur.
     *
     * @param string $profileId Identifiant interne du profil.
     * @param int|string $uid UID décimal ou hexadécimal de la commande.
     * @return array<string,mixed>
     */
    public function getFeatureDefinition($profileId, $uid)
    {
        return $this->withStoreLock(function () use ($profileId, $uid) {
            return $this->getFeatureDefinitionLocked($profileId, $uid);
        });
    }

    /**
     * Relit une définition XML alors que le verrou du magasin est détenu.
     *
     * @param string $profileId Identifiant interne du profil.
     * @param int|string $uid UID de la commande.
     * @return array<string,mixed>
     */
    private function getFeatureDefinitionLocked($profileId, $uid)
    {
        $profile = $this->getProfileLocked($profileId);
        $uidHex = $this->normalizeUid($uid);
        if ($uidHex === '') {
            throw new InvalidArgumentException(__('UID Home Connect invalide', __FILE__));
        }

        $description = $this->loadXmlFile($profile['deviceDescriptionPath'], 'DeviceDescription.xml');
        $mapping = $this->loadXmlFile($profile['featureMappingPath'], 'FeatureMapping.xml');
        $definitionNode = $this->findNodeByUid($description, 'uid', $uidHex);
        $featureNode = $this->findNodeByUid($mapping, 'refUID', $uidHex, 'feature');
        $attributes = array();
        $nodeName = '';
        if (is_object($definitionNode)) {
            $nodeName = $definitionNode->getName();
            foreach ($definitionNode->attributes() as $name => $value) {
                $attributes[(string) $name] = (string) $value;
            }
        }

        $feature = is_object($featureNode) ? trim((string) $featureNode) : '';
        $enumType = isset($attributes['enumerationType'])
            ? $this->normalizeUid($attributes['enumerationType'])
            : '';
        $states = array();
        if ($enumType !== '') {
            foreach ((array) $mapping->xpath('//*[local-name()="enumDescription"]') as $enumNode) {
                if ($this->normalizeUid((string) $enumNode['refENID']) !== $enumType) {
                    continue;
                }
                foreach ((array) $enumNode->xpath('.//*[local-name()="enumMember"]') as $member) {
                    $raw = (string) $member['refValue'];
                    $label = trim((string) $member);
                    if ($raw !== '') {
                        $states[$raw] = $label;
                    }
                }
                break;
            }
        }

        $profilePath = 'data/profiles/' . strtolower((string) $profileId) . '/';
        return array(
            'uid' => hexdec($uidHex),
            'uid_hex' => $uidHex,
            'feature' => $feature,
            'node' => $nodeName,
            'attributes' => $attributes,
            'enum_type' => $enumType,
            'states' => $states,
            'boolean' => (($attributes['refCID'] ?? '') === '01' && ($attributes['refDID'] ?? '') === '00'),
            'device_description' => $profilePath . 'DeviceDescription.xml',
            'feature_mapping' => $profilePath . 'FeatureMapping.xml',
        );
    }

    /**
     * Vérifie les limites de sécurité de l'archive.
     *
     * @param ZipArchive $zip Archive ouverte.
     * @return void
     */
    private function validateArchive(ZipArchive $zip)
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ENTRY_COUNT) {
            throw new InvalidArgumentException(__('Nombre de fichiers anormal dans le profil', __FILE__));
        }
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                continue;
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === ''
                || $name[0] === '/'
                || preg_match('/^[A-Za-z]:\//', $name)
                || preg_match('#(?:^|/)\.\.(?:/|$)#', $name)
                || strpos($name, "\0") !== false) {
                throw new InvalidArgumentException(__('Chemin dangereux détecté dans le ZIP', __FILE__));
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNCOMPRESSED_SIZE) {
                throw new InvalidArgumentException(__('Contenu décompressé du profil trop volumineux', __FILE__));
            }
        }
    }

    /**
     * Charge un XML installé en désactivant tout accès réseau.
     *
     * @param string $path Chemin absolu contrôlé par le magasin.
     * @param string $label Nom présenté dans les erreurs.
     * @return SimpleXMLElement
     */
    private function loadXmlFile($path, $label)
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(__('Le fichier %s est introuvable', __FILE__), $label));
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new RuntimeException(sprintf(__('Le fichier %s est invalide', __FILE__), $label));
        }
        return $xml;
    }

    /**
     * Recherche un élément XML en comparant un UID normalisé.
     *
     * @param SimpleXMLElement $xml Document.
     * @param string $attribute Attribut contenant l'UID.
     * @param string $uidHex UID normalisé.
     * @param string $nodeName Nom d'élément optionnel.
     * @return SimpleXMLElement|null
     */
    private function findNodeByUid($xml, $attribute, $uidHex, $nodeName = '')
    {
        $query = $nodeName === ''
            ? '//*[@' . $attribute . ']'
            : '//*[local-name()="' . $nodeName . '" and @' . $attribute . ']';
        foreach ((array) $xml->xpath($query) as $node) {
            if ($this->normalizeUid((string) $node[$attribute]) === $uidHex) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Normalise un UID Home Connect sur quatre chiffres hexadécimaux.
     *
     * @param int|string $uid UID décimal ou hexadécimal.
     * @return string
     */
    private function normalizeUid($uid)
    {
        if (is_int($uid)) {
            $number = (int) $uid;
        } else {
            $value = preg_replace('/^0x/i', '', trim((string) $uid));
            if ($value === '' || !ctype_xdigit($value)) {
                return '';
            }
            $number = hexdec($value);
        }
        if ($number < 0 || $number > 0xFFFFFFFF) {
            return '';
        }
        return strtoupper(str_pad(dechex($number), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Normalise et enregistre un appareil du ZIP.
     *
     * @param ZipArchive $zip Archive ouverte.
     * @param array<string,mixed> $metadata Métadonnées du profil.
     * @param string $jsonEntry Nom de l'entrée JSON.
     * @return array<string,mixed>
     */
    private function importProfile(ZipArchive $zip, $metadata, $jsonEntry)
    {
        $haId = trim((string) ($metadata['haId'] ?? ''));
        if ($haId === '' || strlen($haId) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $haId)) {
            throw new InvalidArgumentException(__('Identifiant Home Connect invalide', __FILE__));
        }
        $connectionType = strtoupper(trim((string) ($metadata['connectionType'] ?? 'AES')));
        if (!in_array($connectionType, array('AES', 'TLS'), true)) {
            throw new InvalidArgumentException(sprintf(
                __('Transport inconnu pour %s', __FILE__),
                $haId
            ));
        }
        $key = trim((string) ($metadata['key'] ?? ''));
        $decodedKey = $this->base64UrlDecode($key);
        if ($decodedKey === false || strlen($decodedKey) < 16 || strlen($decodedKey) > 64) {
            throw new InvalidArgumentException(sprintf(__('Clé invalide pour %s', __FILE__), $haId));
        }
        $iv = trim((string) ($metadata['iv'] ?? ''));
        if ($connectionType === 'AES') {
            $decodedIv = $this->base64UrlDecode($iv);
            if ($decodedIv === false || strlen($decodedIv) !== 16) {
                throw new InvalidArgumentException(sprintf(__('Vecteur AES invalide pour %s', __FILE__), $haId));
            }
        }

        $baseName = preg_replace('/\.json$/i', '', basename($jsonEntry));
        $descriptionName = (string) ($metadata['deviceDescriptionFileName'] ?? ($baseName . '_DeviceDescription.xml'));
        $featureName = (string) ($metadata['featureMappingFileName'] ?? ($baseName . '_FeatureMapping.xml'));
        $jsonDirectory = str_replace('\\', '/', dirname($jsonEntry));
        $jsonDirectory = $jsonDirectory === '.' ? '' : trim($jsonDirectory, '/');
        $description = $this->readZipEntry($zip, array(
            ($jsonDirectory !== '' ? $jsonDirectory . '/' : '') . ltrim($descriptionName, '/'),
            $descriptionName,
        ), 'DeviceDescription.xml');
        $features = $this->readZipEntry($zip, array(
            ($jsonDirectory !== '' ? $jsonDirectory . '/' : '') . ltrim($featureName, '/'),
            $featureName,
        ), 'FeatureMapping.xml');
        if ($description === '' || $features === '') {
            throw new InvalidArgumentException(sprintf(
                __('Les descriptions XML de %s sont absentes', __FILE__),
                $haId
            ));
        }
        $this->assertXml($description, 'DeviceDescription.xml');
        $this->assertXml($features, 'FeatureMapping.xml');

        $profileId = substr(hash('sha256', $haId), 0, 24);
        $normalized = array(
            'profileSchemaVersion' => self::PROFILE_SCHEMA_VERSION,
            'haId' => $haId,
            'name' => trim((string) ($metadata['name'] ?? '')),
            'type' => trim((string) ($metadata['type'] ?? '')),
            'brand' => trim((string) ($metadata['brand'] ?? '')),
            'vib' => trim((string) ($metadata['vib'] ?? '')),
            'serialNumber' => trim((string) ($metadata['serialNumber'] ?? '')),
            'mac' => trim((string) ($metadata['mac'] ?? '')),
            'host' => trim((string) ($metadata['host'] ?? $metadata['address'] ?? $metadata['ip'] ?? '')),
            'connectionType' => $connectionType,
            'key' => $key,
            'iv' => $iv,
            'importedAt' => date('c'),
        );
        $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new RuntimeException(__('Encodage du profil Home Connect impossible', __FILE__));
        }
        $this->installProfileFiles($profileId, array(
            'DeviceDescription.xml' => $description,
            'FeatureMapping.xml' => $features,
            'device.json' => $encoded . "\n",
        ));
        $this->log('info', sprintf(__('Profil Home Connect importé pour %s', __FILE__), $haId));

        $public = $normalized;
        unset($public['key'], $public['iv']);
        $public['profileId'] = $profileId;
        return $public;
    }

    /**
     * Recherche une entrée par chemin puis par suffixe attendu.
     *
     * @param ZipArchive $zip Archive ouverte.
     * @param string|string[] $requested Chemins possibles annoncés par le JSON.
     * @param string $suffix Suffixe de secours.
     * @return string
     */
    private function readZipEntry(ZipArchive $zip, $requested, $suffix)
    {
        $requestedPaths = array_values(array_unique(array_filter(array_map(function ($path) {
            return ltrim(str_replace('\\', '/', trim((string) $path)), '/');
        }, (array) $requested))));
        $basenameMatches = array();
        $fallbacks = array();
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = is_array($stat) ? ltrim(str_replace('\\', '/', (string) ($stat['name'] ?? '')), '/') : '';
            if (in_array($name, $requestedPaths, true)) {
                $content = $zip->getFromIndex($index);
                return $content === false ? '' : (string) $content;
            }
            foreach ($requestedPaths as $requestedPath) {
                if (basename($name) === basename($requestedPath)) {
                    $basenameMatches[$index] = true;
                }
            }
            if (preg_match('/' . preg_quote($suffix, '/') . '$/i', $name)) {
                $fallbacks[] = $index;
            }
        }
        if (count($basenameMatches) === 1) {
            $index = (int) array_key_first($basenameMatches);
            $content = $zip->getFromIndex($index);
            return $content === false ? '' : (string) $content;
        }
        // Le suffixe générique n'est sûr que lorsqu'il ne peut désigner qu'un
        // seul appareil. Cela évite de mélanger les XML d'un ZIP multi-profils.
        if (count($fallbacks) === 1) {
            $content = $zip->getFromIndex($fallbacks[0]);
            return $content === false ? '' : (string) $content;
        }
        return '';
    }

    /**
     * Valide sommairement un document XML sans autoriser le réseau.
     *
     * @param string $xml Document XML.
     * @param string $label Libellé de diagnostic.
     * @return void
     */
    private function assertXml($xml, $label)
    {
        if (strlen($xml) > 10485760 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new InvalidArgumentException(sprintf(__('%s n’est pas un XML sûr', __FILE__), $label));
        }
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($document === false) {
            throw new InvalidArgumentException(sprintf(__('%s est invalide', __FILE__), $label));
        }
    }

    /**
     * Décode une chaîne Base64 URL avec ou sans remplissage.
     *
     * @param string $value Valeur encodée.
     * @return string|false
     */
    private function base64UrlDecode($value)
    {
        $value = strtr(trim((string) $value), '-_', '+/');
        if ($value === '' || preg_match('/[^A-Za-z0-9+\/=]/', $value)) {
            return false;
        }
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        return base64_decode($value, true);
    }

    /**
     * Écrit atomiquement un fichier privé.
     *
     * @param string $path Destination.
     * @param string $content Contenu.
     * @return void
     */
    private function atomicWrite($path, $content)
    {
        $temporary = tempnam(dirname($path), '.localhomeconnect-');
        if ($temporary === false) {
            throw new RuntimeException(__('Écriture du profil impossible', __FILE__));
        }
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException(__('Écriture du profil impossible', __FILE__));
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException(__('Installation du profil impossible', __FILE__));
        }
        chmod($path, 0600);
    }

    /**
     * Installe les trois fichiers d'un profil comme une transaction unique.
     *
     * @param string $profileId Identifiant interne.
     * @param array<string,string> $files Fichiers validés.
     * @return void
     */
    private function installProfileFiles($profileId, $files)
    {
        $this->withStoreLock(function () use ($profileId, $files) {
            $this->installProfileFilesLocked($profileId, $files);
        });
    }

    /**
     * Exécute une opération sous le verrou global et réentrant du magasin.
     *
     * Un verrou global est volontaire : une importation, une restauration et
     * une lecture ne doivent jamais observer un profil à moitié remplacé.
     *
     * @param callable $callback Opération protégée.
     * @param int $mode LOCK_EX ou LOCK_SH.
     * @return mixed
     */
    private function withStoreLock($callback, $mode = LOCK_EX)
    {
        if ($this->lockDepth > 0) {
            $this->lockDepth++;
            try {
                return call_user_func($callback);
            } finally {
                $this->lockDepth--;
            }
        }
        $path = $this->root . '/.store.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException(__('Verrouillage du magasin de profils impossible', __FILE__));
        }
        @chmod($path, 0600);
        if (!flock($handle, $mode)) {
            @fclose($handle);
            throw new RuntimeException(__('Verrouillage du magasin de profils impossible', __FILE__));
        }
        $this->lockHandle = $handle;
        $this->lockDepth = 1;
        try {
            return call_user_func($callback);
        } finally {
            $this->lockDepth = 0;
            $this->lockHandle = null;
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Installe un profil alors que son verrou exclusif est détenu.
     *
     * @param string $profileId Identifiant interne.
     * @param array<string,string> $files Fichiers validés.
     * @return void
     */
    private function installProfileFilesLocked($profileId, $files)
    {
        $target = $this->root . '/' . $profileId;
        $staging = $this->root . '/.' . $profileId . '.staging-' . bin2hex(random_bytes(6));
        $backup = $this->root . '/.' . $profileId . '.backup-' . bin2hex(random_bytes(6));
        $this->ensureDirectory($staging);
        try {
            foreach ($files as $name => $content) {
                $this->atomicWrite($staging . '/' . $name, $content);
            }
            $hadTarget = is_dir($target);
            if ($hadTarget && !rename($target, $backup)) {
                throw new RuntimeException(__('Sauvegarde de l’ancien profil impossible', __FILE__));
            }
            if (!rename($staging, $target)) {
                if ($hadTarget) {
                    @rename($backup, $target);
                }
                throw new RuntimeException(__('Installation transactionnelle du profil impossible', __FILE__));
            }
            @chmod($target, 0700);
            if ($hadTarget) {
                $this->removeDirectoryQuietly($backup);
            }
        } catch (Throwable $exception) {
            if (is_dir($staging)) {
                $this->removeDirectory($staging);
            }
            if (!is_dir($target) && is_dir($backup)) {
                @rename($backup, $target);
            }
            throw $exception;
        }
    }

    /**
     * Supprime récursivement un répertoire déjà borné par le magasin privé.
     *
     * @param string $directory Répertoire exact.
     * @return void
     */
    private function removeDirectory($directory)
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach ((array) scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } elseif (!@unlink($path)) {
                throw new RuntimeException(__('Suppression d’un fichier de profil impossible', __FILE__));
            }
        }
        if (!@rmdir($directory)) {
            throw new RuntimeException(__('Suppression du répertoire de profil impossible', __FILE__));
        }
    }

    /**
     * Nettoie sans invalider un profil déjà installé avec succès.
     *
     * @param string $directory Répertoire résiduel.
     * @return void
     */
    private function removeDirectoryQuietly($directory)
    {
        try {
            $this->removeDirectory($directory);
        } catch (Throwable $exception) {
            $this->log('warning', $exception->getMessage());
        }
    }

    /**
     * Élimine les répertoires temporaires anciens laissés par un arrêt brutal.
     *
     * Une sauvegarde n'est supprimée que lorsque le profil final correspondant
     * existe. Sinon, getProfilePath() peut encore la restaurer.
     *
     * @return void
     */
    private function cleanupInterruptedInstalls()
    {
        $now = time();
        foreach ((array) glob($this->root . '/.*.staging-*') as $directory) {
            if (is_dir($directory) && $now - (int) @filemtime($directory) > 3600) {
                $this->removeDirectoryQuietly($directory);
            }
        }
        foreach ((array) glob($this->root . '/.*.backup-*') as $directory) {
            $name = basename($directory);
            if (!is_dir($directory)
                || !preg_match('/^\.([a-f0-9]{24})\.backup-/', $name, $matches)
                || !is_dir($this->root . '/' . $matches[1])
                || $now - (int) @filemtime($directory) <= 86400) {
                continue;
            }
            $this->removeDirectoryQuietly($directory);
        }
    }

    /**
     * Crée un répertoire privé.
     *
     * @param string $path Chemin.
     * @return void
     */
    private function ensureDirectory($path)
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException(__('Création du répertoire de profils impossible', __FILE__));
        }
        @chmod($path, 0700);
    }

    /**
     * Envoie un message au journaliseur configuré.
     *
     * @param string $level Niveau.
     * @param string $message Message.
     * @return void
     */
    private function log($level, $message)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $level, $message);
        }
    }
}
