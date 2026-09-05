<?php

/**
 * Signale que SingleKey exige une interaction dans un vrai navigateur.
 */
class LocalHomeConnectBrowserRequiredException extends RuntimeException
{
}

/**
 * Récupère les profils locaux Home Connect au moyen d'OAuth PKCE.
 *
 * Le service tente d'abord le parcours SingleKey côté serveur. Si SingleKey
 * impose une interaction humaine, le même échange PKCE peut être finalisé à
 * partir de la redirection obtenue dans le navigateur.
 */
class LocalHomeConnectCloudService
{
    private const OAUTH_BASE_URL = 'https://api.home-connect.com/security/oauth/';
    private const SINGLEKEY_URL = 'https://singlekey-id.com';
    private const REDIRECT_URI = 'hcauth://auth/prod';
    private const HOME_CONNECT_CLIENT_ID = '9B75AC9EC512F36C84256AC47D813E2C1DD0D6520DF774B020E1E6E2EB29B1F3';
    private const SINGLEKEY_CLIENT_ID = '11F75C04-21C2-4DA9-A623-228B54E9A256';
    private const MAX_REDIRECTS = 15;
    private const MAX_JSON_SIZE = 10485760;
    private const MAX_ZIP_SIZE = 20971520;

    /** @var string Identifiant SingleKey. */
    private $username;

    /** @var string Mot de passe SingleKey. */
    private $password;

    /** @var string Répertoire de travail privé. */
    private $workDirectory;

    /** @var string|null Fichier de cookies de la session HTTP. */
    private $cookieFile;

    /** @var callable|null Journaliseur injecté. */
    private $logger;

    /** @var callable|null Transport HTTP injecté pour les tests. */
    private $requester;

    /** @var float Échéance absolue de l'opération réseau courante. */
    private $deadlineAt = 0.0;

    /**
     * Initialise le service de téléchargement.
     *
     * @param string $username Identifiant SingleKey, vide pour le parcours navigateur.
     * @param string $password Mot de passe SingleKey, vide pour le parcours navigateur.
     * @param string $workDirectory Répertoire privé pour les fichiers temporaires.
     * @param callable|null $logger Journaliseur facultatif.
     * @param callable|null $requester Transport HTTP injecté pour les tests.
     */
    public function __construct($username, $password, $workDirectory, $logger = null, $requester = null)
    {
        $this->username = trim((string) $username);
        $this->password = (string) $password;
        $this->workDirectory = rtrim((string) $workDirectory, '/');
        $this->logger = is_callable($logger) ? $logger : null;
        $this->requester = is_callable($requester) ? $requester : null;

        if (($this->username === '') !== ($this->password === '')) {
            throw new InvalidArgumentException(__('Renseignez à la fois l’identifiant et le mot de passe Home Connect', __FILE__));
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(__('L’extension PHP ZIP est nécessaire', __FILE__));
        }
        if ($this->requester === null && !function_exists('curl_init')) {
            throw new RuntimeException(__('L’extension PHP cURL est nécessaire', __FILE__));
        }
        $this->ensureDirectory($this->workDirectory);
        $this->cookieFile = $this->temporaryFile('cookies-');
    }

    /**
     * Supprime le fichier de cookies éphémère.
     *
     * @return void
     */
    public function __destruct()
    {
        if (is_string($this->cookieFile) && $this->cookieFile !== '') {
            @unlink($this->cookieFile);
        }
    }

    /**
     * Tente l'autorisation SingleKey entièrement côté serveur.
     *
     * @return string Chemin du ZIP temporaire.
     */
    public function downloadArchive()
    {
        $this->beginOperation(240);
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException(__('Les identifiants SingleKey sont nécessaires', __FILE__));
        }
        $this->log('info', __('Connexion directe à Home Connect et SingleKey', __FILE__));
        try {
            $token = $this->authenticateDirectly();
        } finally {
            $this->username = '';
            $this->password = '';
        }
        return $this->downloadArchiveWithToken($token);
    }

    /**
     * Prépare une autorisation destinée au navigateur de l'utilisateur.
     *
     * Le `code_verifier` doit rester côté serveur. Seule l'URL est transmise au
     * navigateur afin que SingleKey puisse exécuter JavaScript et hCaptcha.
     *
     * @return array{url:string,verifier:string,state:string}
     */
    public static function createBrowserAuthorization()
    {
        $verifier = self::base64UrlEncode(random_bytes(32));
        $state = self::base64UrlEncode(random_bytes(24));
        return array(
            'url' => self::authorizationUrl($verifier, $state),
            'verifier' => $verifier,
            'state' => $state,
        );
    }

    /**
     * Prépare Home Connect côté Jeedom puis retourne l'entrée SingleKey.
     *
     * Le navigateur n'a ainsi qu'à gérer SingleKey, JavaScript et hCaptcha. Les
     * cookies Home Connect restent côté Jeedom afin de pouvoir reprendre ensuite
     * la redirection `redirect_target` que la politique CSP peut bloquer.
     *
     * @return array{url:string,verifier:string,state:string,cookies:string}
     */
    public function prepareBrowserAuthorization()
    {
        $this->beginOperation(120);
        $authorization = self::createBrowserAuthorization();
        $authorizePage = $this->followHttpRedirects('GET', $authorization['url']);
        $sessionId = $this->hiddenInput($authorizePage['body'], 'sessionId');
        if ($sessionId === '') {
            throw new RuntimeException(__('Home Connect n’a pas fourni de session OAuth exploitable', __FILE__));
        }
        return array(
            'url' => self::singleKeyAuthorizationUrl($sessionId),
            'verifier' => $authorization['verifier'],
            'state' => $authorization['state'],
            'cookies' => $this->sessionCookies(),
        );
    }

    /**
     * Extrait et valide une redirection produite par le parcours navigateur.
     *
     * SingleKey peut exposer soit la redirection finale `hcauth://`, soit la
     * requête intermédiaire Home Connect bloquée par sa politique CSP. Une ligne
     * complète copiée depuis la console est acceptée, mais aucun autre hôte ou
     * chemin ne l'est.
     *
     * @param string $input URL ou ligne de console copiée par l'utilisateur.
     * @return string URL normalisée.
     */
    public static function normalizeBrowserRedirect($input)
    {
        $input = html_entity_decode(trim((string) $input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($input === '' || strlen($input) > 16384) {
            throw new InvalidArgumentException(__('Collez la redirection complète affichée par le navigateur', __FILE__));
        }
        if (preg_match(
            '~(?:hcauth://auth/prod|https://api\.home-connect\.com/security/oauth/redirect_target)\?[^\s"\'<>]+~i',
            $input,
            $matches
        )) {
            $input = rtrim($matches[0], '),.;');
        }

        $scheme = strtolower((string) parse_url($input, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($input, PHP_URL_HOST));
        $path = rtrim((string) parse_url($input, PHP_URL_PATH), '/');
        $isFinal = $scheme === 'hcauth' && $host === 'auth' && $path === '/prod';
        $isIntermediate = $scheme === 'https'
            && $host === 'api.home-connect.com'
            && $path === '/security/oauth/redirect_target';
        if (!$isFinal && !$isIntermediate) {
            throw new InvalidArgumentException(__('Cette adresse n’est pas une redirection Home Connect reconnue', __FILE__));
        }

        $parameters = array();
        parse_str((string) parse_url($input, PHP_URL_QUERY), $parameters);
        if (empty($parameters['code']) || empty($parameters['state'])) {
            throw new InvalidArgumentException(__('La redirection Home Connect ne contient pas le code et l’état attendus', __FILE__));
        }
        return $input;
    }

    /**
     * Reproduit le parcours SingleKey tant qu'il ne demande pas d'interaction.
     *
     * @return string Jeton OAuth éphémère.
     */
    private function authenticateDirectly()
    {
        $authorization = self::createBrowserAuthorization();
        $authorizePage = $this->followHttpRedirects('GET', $authorization['url']);
        $sessionId = $this->hiddenInput($authorizePage['body'], 'sessionId');
        if ($sessionId === '') {
            throw new RuntimeException(__('Home Connect n’a pas fourni de session OAuth exploitable', __FILE__));
        }

        $singleKeyUrl = self::singleKeyAuthorizationUrl($sessionId);
        $emailPage = $this->followHttpRedirects('GET', $singleKeyUrl);
        $returnUrl = $this->queryParameter($emailPage['url'], 'ReturnUrl');

        $emailForm = $this->credentialForm($emailPage['body'], $emailPage['url'], 'email', $this->username);
        $emailResponse = $this->request('POST', $emailForm['url'], $emailForm['fields']);
        $emailLocation = $this->header($emailResponse, 'location');
        if ($this->isRedirect($emailResponse['status']) && $emailLocation !== '') {
            $passwordPage = $this->followHttpRedirects('GET', $this->resolveUrl($emailForm['url'], $emailLocation));
        } elseif ($emailResponse['status'] >= 200 && $emailResponse['status'] < 300) {
            $passwordPage = $emailResponse;
            $passwordPage['url'] = $emailForm['url'];
        } else {
            $this->throwInteractiveLoginRequired($emailResponse['body']);
        }

        $passwordForm = $this->credentialForm($passwordPage['body'], $passwordPage['url'], 'password', $this->password);
        $passwordResponse = $this->request('POST', $passwordForm['url'], $passwordForm['fields']);
        $passwordLocation = $this->header($passwordResponse, 'location');
        if (!$this->isRedirect($passwordResponse['status'])) {
            $this->throwInteractiveLoginRequired($passwordResponse['body']);
        }
        $continuation = $passwordLocation !== ''
            ? $this->resolveUrl($passwordForm['url'], $passwordLocation)
            : $this->resolveUrl($emailPage['url'], $returnUrl);
        $redirect = $this->captureCustomRedirect($continuation);
        return $this->exchangeAuthorizationRedirect(
            $redirect,
            $authorization['verifier'],
            $authorization['state']
        );
    }

    /**
     * Termine une autorisation réalisée dans le navigateur et crée le ZIP.
     *
     * @param string $redirectUrl URL Home Connect bloquée ou URL `hcauth://` finale.
     * @param string $verifier Vérificateur PKCE conservé par Jeedom.
     * @param string $expectedState État OAuth conservé par Jeedom.
     * @param string $cookies Cookies Home Connect préparés côté Jeedom.
     * @return string Chemin du ZIP temporaire.
     */
    public function downloadArchiveFromBrowserRedirect($redirectUrl, $verifier, $expectedState, $cookies = '')
    {
        $this->beginOperation(240);
        $this->log('info', __('Finalisation de l’autorisation Home Connect depuis le navigateur', __FILE__));
        $redirectUrl = self::normalizeBrowserRedirect($redirectUrl);
        if (stripos($redirectUrl, 'https://') === 0) {
            $this->restoreSessionCookies($cookies);
            $this->log('info', __('Poursuite côté Jeedom de la redirection Home Connect bloquée par le navigateur', __FILE__));
            $redirectUrl = $this->captureCustomRedirect($redirectUrl);
        }
        $token = $this->exchangeAuthorizationRedirect($redirectUrl, $verifier, $expectedState);
        return $this->downloadArchiveWithToken($token);
    }

    /**
     * Construit le ZIP agrégé à partir d'un jeton Home Connect éphémère.
     *
     * @param string $token Jeton OAuth ReadOrigApi.
     * @return string Chemin du ZIP temporaire.
     */
    private function downloadArchiveWithToken($token)
    {
        $inventory = $this->accountAppliances($token);
        if (count($inventory['appliances']) === 0) {
            throw new RuntimeException(__('Aucun appareil Home Connect associé à ce compte', __FILE__));
        }

        $archivePath = $this->temporaryFile('profiles-');
        $archive = new ZipArchive();
        $opened = false;
        $complete = false;
        try {
            if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(__('Création de l’archive Home Connect impossible', __FILE__));
            }
            $opened = true;
            $downloaded = 0;
            foreach ($inventory['appliances'] as $appliance) {
                if (!is_array($appliance) || !empty($appliance['isDemo'])) {
                    continue;
                }
                $metadata = $this->normalizeAppliance($appliance);
                $haId = $metadata['haId'];
                if (empty($metadata['key'])) {
                    $encryption = $this->encryptionInformation($token, $haId, $inventory['assets']);
                    $metadata = $this->applyEncryptionInformation($metadata, $encryption);
                }
                if (empty($metadata['key'])) {
                    throw new RuntimeException(sprintf(__('Aucune clé locale reçue pour %s', __FILE__), $haId));
                }

                $this->log('info', sprintf(__('Téléchargement du profil IDDF de %s', __FILE__), $haId));
                $iddf = $this->downloadIddf($token, $haId, $inventory['assets']);
                $files = $this->readIddfArchive($iddf, $haId);
                $folder = substr(hash('sha256', $haId), 0, 24);
                $descriptionName = basename($files['description']['name']);
                $featureName = basename($files['feature']['name']);
                $metadata['deviceDescriptionFileName'] = $descriptionName;
                $metadata['featureMappingFileName'] = $featureName;

                $archive->addFromString($folder . '/' . $descriptionName, $files['description']['content']);
                $archive->addFromString($folder . '/' . $featureName, $files['feature']['content']);
                $archive->addFromString(
                    $folder . '/device.json',
                    json_encode(
                        $metadata,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                    ) . "\n"
                );
                $downloaded++;
            }
            if ($downloaded === 0) {
                throw new RuntimeException(__('Aucun profil Home Connect complet n’a été téléchargé', __FILE__));
            }
            $archive->close();
            $opened = false;
            chmod($archivePath, 0600);
            $complete = true;
            $this->log('info', sprintf(__('%s profil(s) Home Connect téléchargé(s)', __FILE__), $downloaded));
            return $archivePath;
        } finally {
            if ($opened) {
                $archive->close();
            }
            if (!$complete) {
                @unlink($archivePath);
            }
        }
    }

    /**
     * Construit l'URL OAuth publique associée à un couple PKCE/état.
     *
     * @param string $verifier Vérificateur PKCE.
     * @param string $state État OAuth aléatoire.
     * @return string URL Home Connect.
     */
    private static function authorizationUrl($verifier, $state)
    {
        $challenge = self::base64UrlEncode(hash('sha256', $verifier, true));
        return self::OAUTH_BASE_URL . 'authorize?' . http_build_query(array(
            'response_type' => 'code',
            'prompt' => 'login',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'client_id' => self::HOME_CONNECT_CLIENT_ID,
            'scope' => 'ReadOrigApi',
            'nonce' => self::base64UrlEncode(random_bytes(16)),
            'state' => $state,
            'redirect_uri' => self::REDIRECT_URI,
            'redirect_target' => 'icore',
        ), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Construit l'entrée SingleKey correspondant à une session Home Connect.
     *
     * @param string $sessionId Identifiant opaque fourni par Home Connect.
     * @return string URL SingleKey.
     */
    private static function singleKeyAuthorizationUrl($sessionId)
    {
        return self::SINGLEKEY_URL . '/auth/connect/authorize?' . http_build_query(array(
            'client_id' => self::SINGLEKEY_CLIENT_ID,
            'redirect_uri' => self::OAUTH_BASE_URL . 'redirect_target',
            'response_type' => 'code',
            'scope' => 'openid email profile offline_access homeconnect.general',
            'prompt' => 'login',
            'style_id' => 'bsh_hc_01',
            'state' => '{"session_id":"' . $sessionId . '"}',
        ), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Valide la redirection finale puis échange son code contre un jeton.
     *
     * @param string $redirectUrl URL finale `hcauth://`.
     * @param string $verifier Vérificateur PKCE.
     * @param string $expectedState État OAuth attendu.
     * @return string Jeton OAuth éphémère.
     */
    private function exchangeAuthorizationRedirect($redirectUrl, $verifier, $expectedState)
    {
        $redirectUrl = html_entity_decode(trim((string) $redirectUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $verifier = trim((string) $verifier);
        $expectedState = trim((string) $expectedState);
        if (strlen($redirectUrl) > 8192 || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            throw new InvalidArgumentException(__('Données PKCE Home Connect invalides', __FILE__));
        }
        $scheme = strtolower((string) parse_url($redirectUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($redirectUrl, PHP_URL_HOST));
        $path = rtrim((string) parse_url($redirectUrl, PHP_URL_PATH), '/');
        if ($scheme !== 'hcauth' || $host !== 'auth' || $path !== '/prod') {
            throw new InvalidArgumentException(__('Collez l’URL complète commençant par hcauth://auth/prod', __FILE__));
        }
        $parameters = array();
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $parameters);
        if (!empty($parameters['error'])) {
            throw new RuntimeException(__('Autorisation Home Connect refusée : ', __FILE__) . trim((string) $parameters['error']));
        }
        $code = trim((string) ($parameters['code'] ?? ''));
        $returnedState = trim((string) ($parameters['state'] ?? ''));
        if ($code === '' || $returnedState === '' || $expectedState === '' || !hash_equals($expectedState, $returnedState)) {
            throw new RuntimeException(__('La redirection OAuth Home Connect est invalide ou ne correspond pas à cette session', __FILE__));
        }

        $tokenResponse = $this->request('POST', self::OAUTH_BASE_URL . 'token', array(
            'grant_type' => 'authorization_code',
            'client_id' => self::HOME_CONNECT_CLIENT_ID,
            'code_verifier' => $verifier,
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
        ));
        $token = $this->decodeJsonResponse($tokenResponse, __('Échange du code OAuth impossible', __FILE__));
        $accessToken = trim((string) ($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException(__('Home Connect n’a pas retourné de jeton d’accès', __FILE__));
        }
        $this->log('info', __('Autorisation Home Connect obtenue', __FILE__));
        return $accessToken;
    }

    /**
     * Récupère l'inventaire du compte et détermine les hôtes de profils.
     *
     * @param string $token Jeton OAuth.
     * @return array{appliances:array<int,array<string,mixed>>,assets:string[]}
     */
    private function accountAppliances($token)
    {
        $claims = $this->tokenClaims($token);
        $assets = $this->assetCandidates((string) ($claims['region'] ?? 'EU'));
        $lastError = null;
        $accountId = trim((string) ($claims['sub'] ?? ''));
        foreach ($assets as $asset) {
            try {
                $account = $this->requestJson('GET', $asset . '/account/details', $token);
                $appliances = $account['data']['homeAppliances'] ?? ($account['homeAppliances'] ?? array());
                if (is_array($appliances) && count($appliances) > 0) {
                    return array('appliances' => array_values($appliances), 'assets' => $this->prioritize($asset, $assets));
                }
                $accountId = trim((string) (
                    $account['data']['hcId']
                    ?? $account['data']['id']
                    ?? $account['hcId']
                    ?? $account['id']
                    ?? $accountId
                ));
            } catch (RuntimeException $exception) {
                $lastError = $exception;
            }
        }

        if ($accountId !== '') {
            foreach ($assets as $asset) {
                try {
                    $paired = $this->requestJson(
                        'GET',
                        $asset . '/api/account/v2/accounts/' . rawurlencode($accountId) . '/paired-appliances',
                        $token
                    );
                    $appliances = $paired['appliances'] ?? ($paired['data']['appliances'] ?? array());
                    if (is_array($appliances) && count($appliances) > 0) {
                        return array('appliances' => array_values($appliances), 'assets' => $this->prioritize($asset, $assets));
                    }
                } catch (RuntimeException $exception) {
                    $lastError = $exception;
                }
            }
        }
        throw $lastError ?: new RuntimeException(__('Inventaire Home Connect introuvable', __FILE__));
    }

    /**
     * Normalise les métadonnées d'un appareil retourné par Home Connect.
     *
     * @param array<string,mixed> $appliance Appareil distant.
     * @return array<string,mixed>
     */
    private function normalizeAppliance($appliance)
    {
        $haId = trim((string) ($appliance['identifier'] ?? $appliance['haId'] ?? ''));
        if ($haId === '' || strlen($haId) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $haId)) {
            throw new RuntimeException(__('Identifiant Home Connect invalide dans l’inventaire', __FILE__));
        }
        $metadata = array(
            'haId' => $haId,
            'name' => trim((string) ($appliance['name'] ?? $appliance['type'] ?? $haId)),
            'type' => trim((string) ($appliance['type'] ?? $appliance['haType'] ?? '')),
            'brand' => trim((string) ($appliance['brand'] ?? '')),
            'vib' => trim((string) ($appliance['vib'] ?? '')),
            'serialNumber' => trim((string) ($appliance['serialnumber'] ?? $appliance['serialNumber'] ?? '')),
            'mac' => trim((string) ($appliance['mac'] ?? '')),
            'connectionType' => '',
            'key' => '',
            'iv' => '',
        );
        if (!empty($appliance['tls']['key'])) {
            $metadata['connectionType'] = 'TLS';
            $metadata['key'] = (string) $appliance['tls']['key'];
        } elseif (!empty($appliance['aes']['key']) && !empty($appliance['aes']['iv'])) {
            $metadata['connectionType'] = 'AES';
            $metadata['key'] = (string) $appliance['aes']['key'];
            $metadata['iv'] = (string) $appliance['aes']['iv'];
        } elseif (!empty($appliance['key'])) {
            $metadata['connectionType'] = strtoupper((string) ($appliance['connectionType'] ?? 'TLS'));
            $metadata['key'] = (string) $appliance['key'];
            $metadata['iv'] = (string) ($appliance['iv'] ?? '');
        }
        return $metadata;
    }

    /**
     * Récupère les clés séparément lorsque l'inventaire ne les contient pas.
     *
     * @param string $token Jeton OAuth.
     * @param string $haId Identifiant d'appareil.
     * @param string[] $assets Hôtes à essayer.
     * @return array<string,mixed>
     */
    private function encryptionInformation($token, $haId, $assets)
    {
        $lastError = null;
        foreach ($assets as $asset) {
            try {
                return $this->requestJson(
                    'GET',
                    $asset . '/api/appliance/v2/appliances/' . rawurlencode($haId) . '/encryption-information',
                    $token
                );
            } catch (RuntimeException $exception) {
                $lastError = $exception;
            }
        }
        throw $lastError ?: new RuntimeException(sprintf(__('Clés locales introuvables pour %s', __FILE__), $haId));
    }

    /**
     * Applique une réponse encryption-information aux métadonnées.
     *
     * @param array<string,mixed> $metadata Métadonnées existantes.
     * @param array<string,mixed> $encryption Réponse distante.
     * @return array<string,mixed>
     */
    private function applyEncryptionInformation($metadata, $encryption)
    {
        $payload = is_array($encryption['data'] ?? null) ? $encryption['data'] : $encryption;
        if (!empty($payload['tls']['key'])) {
            $metadata['connectionType'] = 'TLS';
            $metadata['key'] = (string) $payload['tls']['key'];
            $metadata['iv'] = '';
        } elseif (!empty($payload['aes']['key']) && !empty($payload['aes']['iv'])) {
            $metadata['connectionType'] = 'AES';
            $metadata['key'] = (string) $payload['aes']['key'];
            $metadata['iv'] = (string) $payload['aes']['iv'];
        }
        return $metadata;
    }

    /**
     * Télécharge le ZIP IDDF d'un appareil.
     *
     * @param string $token Jeton OAuth.
     * @param string $haId Identifiant d'appareil.
     * @param string[] $assets Hôtes à essayer.
     * @return string Données ZIP binaires.
     */
    private function downloadIddf($token, $haId, $assets)
    {
        $lastError = null;
        foreach ($assets as $asset) {
            try {
                $response = $this->request('GET', $asset . '/api/iddf/v1/iddf/' . rawurlencode($haId), null, array(
                    'Authorization: Bearer ' . $token,
                    'Accept: application/zip, application/octet-stream, */*',
                ));
                if ($response['status'] >= 200 && $response['status'] < 300 && $response['body'] !== '') {
                    if (strlen($response['body']) > self::MAX_ZIP_SIZE) {
                        throw new RuntimeException(__('Le ZIP IDDF dépasse 20 Mo', __FILE__));
                    }
                    return $response['body'];
                }
                throw new RuntimeException(sprintf(__('Téléchargement IDDF refusé (HTTP %s)', __FILE__), $response['status']));
            } catch (RuntimeException $exception) {
                $lastError = $exception;
            }
        }
        throw $lastError ?: new RuntimeException(sprintf(__('Profil IDDF introuvable pour %s', __FILE__), $haId));
    }

    /**
     * Extrait uniquement les deux XML utiles d'un ZIP IDDF.
     *
     * @param string $binary Contenu ZIP.
     * @param string $haId Identifiant utilisé pour le diagnostic.
     * @return array<string,array{name:string,content:string}>
     */
    private function readIddfArchive($binary, $haId)
    {
        $path = $this->temporaryFile('iddf-');
        try {
            if (file_put_contents($path, $binary, LOCK_EX) === false) {
                throw new RuntimeException(__('Écriture temporaire du ZIP IDDF impossible', __FILE__));
            }
            chmod($path, 0600);
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException(sprintf(__('Le profil IDDF de %s n’est pas un ZIP valide', __FILE__), $haId));
            }
            try {
                if ($zip->numFiles <= 0 || $zip->numFiles > 100) {
                    throw new RuntimeException(__('Nombre de fichiers IDDF anormal', __FILE__));
                }
                $files = array();
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $stat = $zip->statIndex($index);
                    $name = is_array($stat) ? str_replace('\\', '/', (string) ($stat['name'] ?? '')) : '';
                    if ($name === '' || strpos($name, '../') !== false || strpos($name, "\0") !== false) {
                        throw new RuntimeException(__('Chemin dangereux détecté dans le ZIP IDDF', __FILE__));
                    }
                    $kind = null;
                    if (preg_match('/_FeatureMapping\.xml$/i', $name)) {
                        $kind = 'feature';
                    } elseif (preg_match('/_DeviceDescription\.xml$/i', $name)) {
                        $kind = 'description';
                    }
                    if ($kind === null || isset($files[$kind])) {
                        continue;
                    }
                    if ((int) ($stat['size'] ?? 0) > 10485760) {
                        throw new RuntimeException(__('Un XML IDDF dépasse 10 Mo', __FILE__));
                    }
                    $content = $zip->getFromIndex($index);
                    if ($content === false || $content === '') {
                        throw new RuntimeException(__('Lecture d’un XML IDDF impossible', __FILE__));
                    }
                    $files[$kind] = array('name' => basename($name), 'content' => (string) $content);
                }
                if (!isset($files['feature'], $files['description'])) {
                    throw new RuntimeException(sprintf(__('Les XML IDDF de %s sont incomplets', __FILE__), $haId));
                }
                return $files;
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * Effectue une requête JSON authentifiée.
     *
     * @param string $method Méthode HTTP.
     * @param string $url URL distante.
     * @param string $token Jeton OAuth.
     * @return array<string,mixed>
     */
    private function requestJson($method, $url, $token)
    {
        $response = $this->request($method, $url, null, array(
            'Authorization: Bearer ' . $token,
            'Accept: application/json, */*',
        ));
        return $this->decodeJsonResponse($response, __('Requête Home Connect refusée', __FILE__));
    }

    /**
     * Valide et décode une réponse JSON HTTP.
     *
     * @param array<string,mixed> $response Réponse HTTP.
     * @param string $message Message d'erreur.
     * @return array<string,mixed>
     */
    private function decodeJsonResponse($response, $message)
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(sprintf(__('%s (HTTP %s)', __FILE__), $message, $response['status']));
        }
        if (strlen((string) $response['body']) > self::MAX_JSON_SIZE) {
            throw new RuntimeException(__('Réponse JSON Home Connect trop volumineuse', __FILE__));
        }
        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException(__('Réponse JSON Home Connect invalide', __FILE__));
        }
        return $decoded;
    }

    /**
     * Suit les redirections HTTPS autorisées sans quitter la session cURL.
     *
     * @param string $method Méthode initiale.
     * @param string $url URL initiale.
     * @return array<string,mixed> Réponse avec son URL finale.
     */
    private function followHttpRedirects($method, $url)
    {
        $current = $url;
        for ($index = 0; $index < self::MAX_REDIRECTS; $index++) {
            $response = $this->request($method, $current);
            $response['url'] = $current;
            $location = $this->header($response, 'location');
            if (!$this->isRedirect($response['status']) || $location === '') {
                return $response;
            }
            $current = $this->resolveUrl($current, $location);
            if (stripos($current, self::REDIRECT_URI) === 0) {
                $response['url'] = $current;
                return $response;
            }
            $method = 'GET';
        }
        throw new RuntimeException(__('Trop de redirections HTTP Home Connect', __FILE__));
    }

    /**
     * Extrait la valeur d'un champ caché d'un formulaire HTML.
     *
     * @param string $html Document HTML.
     * @param string $name Nom du champ.
     * @return string
     */
    private function hiddenInput($html, $name)
    {
        $parsed = $this->htmlDocument($html);
        if ($parsed === null) {
            return '';
        }
        $nodes = $parsed['xpath']->query('//input[@name=' . $this->xpathLiteral($name) . ']/@value');
        return $nodes !== false && $nodes->length > 0 ? trim((string) $nodes->item(0)->nodeValue) : '';
    }

    /**
     * Extrait un formulaire de connexion SingleKey sans figer ses noms de champs.
     *
     * @param string $html Page SingleKey.
     * @param string $pageUrl URL de la page.
     * @param string $kind Type de donnée : `email` ou `password`.
     * @param string $value Valeur à soumettre, jamais journalisée.
     * @return array{url:string,fields:array<string,string>}
     */
    private function credentialForm($html, $pageUrl, $kind, $value)
    {
        $parsed = $this->htmlDocument($html);
        if ($parsed === null) {
            $this->throwInteractiveLoginRequired($html);
        }
        $xpath = $parsed['xpath'];
        $protected = $xpath->query('//*[@data-sitekey or contains(concat(" ", normalize-space(@class), " "), " button--protected ")]');
        if ($protected !== false && $protected->length > 0) {
            throw new LocalHomeConnectBrowserRequiredException(__('SingleKey exige hCaptcha. Poursuivez avec l’autorisation dans le navigateur.', __FILE__));
        }

        $translate = 'translate(%s,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")';
        $type = sprintf($translate, '@type');
        $autocomplete = sprintf($translate, '@autocomplete');
        $name = sprintf($translate, '@name');
        if ($kind === 'email') {
            $selector = $type . '="email" or ' . $autocomplete . '="username"'
                . ' or ((' . $type . '="text" or not(@type)) and (contains(' . $name . ',"email") or contains(' . $name . ',"useridentifier")))';
        } elseif ($kind === 'password') {
            $selector = $type . '="password" or ' . $autocomplete . '="current-password"'
                . ' or (' . $type . '!="hidden" and contains(' . $name . ',"password"))';
        } else {
            throw new InvalidArgumentException(__('Type de formulaire SingleKey inconnu', __FILE__));
        }

        $forms = $xpath->query('//form[.//input[' . $selector . ']]');
        if ($forms === false || $forms->length === 0) {
            $this->throwInteractiveLoginRequired($html);
        }
        $form = $forms->item(0);
        $credentialNodes = $xpath->query('.//input[' . $selector . ']', $form);
        $credential = $credentialNodes !== false && $credentialNodes->length > 0 ? $credentialNodes->item(0) : null;
        $credentialName = $credential instanceof DOMElement ? trim((string) $credential->getAttribute('name')) : '';
        if ($credentialName === '') {
            throw new RuntimeException(__('Le champ de connexion SingleKey n’a pas de nom', __FILE__));
        }

        $action = trim((string) $form->getAttribute('action'));
        $formUrl = $action === '' ? $pageUrl : $this->resolveUrl($pageUrl, $action);
        $this->assertCredentialUrl($formUrl);
        $fields = array();
        $inputs = $xpath->query('.//input[@name]', $form);
        if ($inputs !== false) {
            foreach ($inputs as $input) {
                $inputType = strtolower(trim((string) $input->getAttribute('type')));
                $inputName = trim((string) $input->getAttribute('name'));
                if (
                    $inputName === ''
                    || $input->hasAttribute('disabled')
                    || in_array($inputType, array('button', 'file', 'image', 'reset', 'submit'), true)
                    || (in_array($inputType, array('checkbox', 'radio'), true) && !$input->hasAttribute('checked'))
                ) {
                    continue;
                }
                $fields[$inputName] = $input->isSameNode($credential)
                    ? (string) $value
                    : (string) $input->getAttribute('value');
            }
        }
        $fields[$credentialName] = (string) $value;
        $this->appendSubmitControl($xpath, $form, $fields);
        $this->log('debug', sprintf(
            __('Formulaire SingleKey %s : %s, champ %s', __FILE__),
            $kind,
            $this->safeUrlLabel($formUrl),
            $credentialName
        ));
        return array('url' => $formUrl, 'fields' => $fields);
    }

    /**
     * Ajoute la paire nom/valeur du bouton principal envoyé par un navigateur.
     *
     * @param DOMXPath $xpath Analyseur XPath.
     * @param DOMElement $form Formulaire.
     * @param array<string,string> $fields Champs modifiés par référence.
     * @return void
     */
    private function appendSubmitControl($xpath, $form, &$fields)
    {
        $buttons = $xpath->query(
            './/button[@name and not(@disabled) and (not(@type) or translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="submit")]'
            . ' | .//input[@name and not(@disabled) and translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="submit"]',
            $form
        );
        if ($buttons === false) {
            return;
        }
        foreach ($buttons as $button) {
            $name = trim((string) $button->getAttribute('name'));
            $value = trim((string) $button->getAttribute('value'));
            $label = strtolower($name . ' ' . $value . ' ' . $button->textContent);
            if ($name !== '' && !preg_match('/register|create|forgot|reset|cancel|back/', $label)) {
                $fields[$name] = $value !== '' ? $value : trim((string) $button->textContent);
                return;
            }
        }
    }

    /**
     * Charge un document HTML sans autoriser les accès réseau XML.
     *
     * @param string $html Document HTML.
     * @return array{document:DOMDocument,xpath:DOMXPath}|null
     */
    private function htmlDocument($html)
    {
        if (!class_exists('DOMDocument')) {
            throw new RuntimeException(__('L’extension PHP XML est nécessaire', __FILE__));
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML((string) $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $loaded ? array('document' => $document, 'xpath' => new DOMXPath($document)) : null;
    }

    /**
     * Produit un littéral XPath sûr.
     *
     * @param string $value Valeur.
     * @return string
     */
    private function xpathLiteral($value)
    {
        return strpos($value, "'") === false ? "'" . $value . "'" : '"' . str_replace('"', '', $value) . '"';
    }

    /**
     * Suit le retour SingleKey jusqu'à la redirection personnalisée finale.
     *
     * @param string $url URL de continuation.
     * @return string URL `hcauth://`.
     */
    private function captureCustomRedirect($url)
    {
        $current = $url;
        for ($index = 0; $index < self::MAX_REDIRECTS; $index++) {
            if (stripos($current, self::REDIRECT_URI) === 0) {
                return $current;
            }
            $response = $this->request('GET', $current);
            $location = $this->header($response, 'location');
            if (!$this->isRedirect($response['status']) || $location === '') {
                $this->throwInteractiveLoginRequired($response['body']);
            }
            $current = $this->resolveUrl($current, $location);
        }
        throw new RuntimeException(__('Trop de redirections pendant l’autorisation Home Connect', __FILE__));
    }

    /**
     * Transforme un écran interactif SingleKey en diagnostic exploitable.
     *
     * @param string $html Réponse HTML, jamais journalisée.
     * @return void
     */
    private function throwInteractiveLoginRequired($html)
    {
        $content = strtolower(strip_tags((string) $html));
        if (strpos($content, 'captcha') !== false) {
            throw new LocalHomeConnectBrowserRequiredException(__('SingleKey exige hCaptcha. Poursuivez avec l’autorisation dans le navigateur.', __FILE__));
        }
        if (preg_match('/two-factor|verification code|security code|one-time|otp/', $content)) {
            throw new LocalHomeConnectBrowserRequiredException(__('SingleKey exige une validation interactive. Poursuivez avec l’autorisation dans le navigateur.', __FILE__));
        }
        if (strpos($content, 'password') !== false || strpos($content, 'mot de passe') !== false) {
            throw new RuntimeException(__('SingleKey a refusé la connexion : vérifiez vos identifiants', __FILE__));
        }
        throw new LocalHomeConnectBrowserRequiredException(__('Le parcours SingleKey nécessite le navigateur', __FILE__));
    }

    /**
     * Exécute une requête HTTP sans suivre automatiquement les redirections.
     *
     * @param string $method Méthode HTTP.
     * @param string $url URL distante.
     * @param array<string,mixed>|null $form Formulaire éventuel.
     * @param string[] $headers En-têtes supplémentaires.
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function request($method, $url, $form = null, $headers = array())
    {
        $remaining = $this->remainingOperationSeconds();
        $this->assertAllowedUrl($url);
        $method = strtoupper((string) $method);
        if ($this->requester !== null) {
            $response = call_user_func($this->requester, $method, $url, $form, $headers);
            if (!is_array($response) || !isset($response['status'], $response['body'])) {
                throw new RuntimeException(__('Transport HTTP de test invalide', __FILE__));
            }
            $response['headers'] = is_array($response['headers'] ?? null) ? $response['headers'] : array();
            if (strlen((string) $response['body']) > self::MAX_ZIP_SIZE) {
                throw new RuntimeException(__('Réponse Home Connect trop volumineuse', __FILE__));
            }
            return $response;
        }

        $responseHeaders = array();
        $responseBody = '';
        $responseTooLarge = false;
        $curl = curl_init($url);
        $defaultHeaders = array(
            'Accept: */*',
            'User-Agent: Jeedom-LocalHomeConnect/1.0',
            'Connection: keep-alive',
        );
        if ($form !== null) {
            $defaultHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, min(15, $remaining)),
            CURLOPT_TIMEOUT => max(1, min(90, $remaining)),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
            CURLOPT_ENCODING => '',
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($curlHandle, $line) use (&$responseHeaders) {
                $length = strlen($line);
                if (stripos($line, 'HTTP/') === 0) {
                    $responseHeaders = array();
                    return $length;
                }
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($curlHandle, $chunk) use (&$responseBody, &$responseTooLarge) {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_ZIP_SIZE) {
                    $responseTooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ));
        if ($form !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $this->query($form));
        }
        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        unset($curl);
        $this->log('debug', sprintf(
            __('HTTP %s %s : %s', __FILE__),
            $method,
            $this->safeUrlLabel($url),
            $status
        ));
        if ($responseTooLarge) {
            throw new RuntimeException(__('Réponse Home Connect trop volumineuse', __FILE__));
        }
        if ($success === false) {
            throw new RuntimeException(__('Connexion HTTP Home Connect impossible : ', __FILE__) . $error);
        }
        return array('status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody);
    }

    /**
     * Borne la durée totale d'un parcours cloud, redirections comprises.
     *
     * @param int $seconds Durée maximale.
     * @return void
     */
    private function beginOperation($seconds)
    {
        $this->deadlineAt = microtime(true) + max(10, (int) $seconds);
    }

    /**
     * Retourne le temps encore disponible pour la requête courante.
     *
     * @return int Secondes restantes.
     */
    private function remainingOperationSeconds()
    {
        if ($this->deadlineAt <= 0) {
            $this->beginOperation(240);
        }
        $remaining = (int) ceil($this->deadlineAt - microtime(true));
        if ($remaining <= 0) {
            throw new RuntimeException(__('Le parcours Home Connect a dépassé sa durée maximale', __FILE__));
        }
        return $remaining;
    }

    /**
     * Retourne le cookie jar éphémère créé pendant la préparation Home Connect.
     *
     * @return string Contenu Netscape du cookie jar, jamais envoyé au navigateur.
     */
    private function sessionCookies()
    {
        clearstatcache(true, $this->cookieFile);
        if (!is_file($this->cookieFile) || filesize($this->cookieFile) > 65536) {
            return '';
        }
        $cookies = file_get_contents($this->cookieFile);
        return is_string($cookies) ? $cookies : '';
    }

    /**
     * Restaure la session Home Connect préparée avant l'ouverture de SingleKey.
     *
     * @param string $cookies Contenu privé conservé dans le cache Jeedom.
     * @return void
     */
    private function restoreSessionCookies($cookies)
    {
        $cookies = (string) $cookies;
        if ($cookies === '') {
            return;
        }
        if (strlen($cookies) > 65536 || strpos($cookies, "\0") !== false) {
            throw new InvalidArgumentException(__('Session Home Connect invalide', __FILE__));
        }
        if (file_put_contents($this->cookieFile, $cookies, LOCK_EX) === false) {
            throw new RuntimeException(__('Restauration de la session Home Connect impossible', __FILE__));
        }
        chmod($this->cookieFile, 0600);
    }

    /**
     * Valide une URL distante avant toute requête.
     *
     * @param string $url URL à valider.
     * @return void
     */
    private function assertAllowedUrl($url)
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($scheme !== 'https' || !$this->hostAllowed($host)) {
            throw new RuntimeException(__('Redirection Home Connect vers un hôte non autorisé', __FILE__));
        }
    }

    /**
     * Vérifie qu'un formulaire d'identifiants reste hébergé par SingleKey.
     *
     * @param string $url URL du formulaire.
     * @return void
     */
    private function assertCredentialUrl($url)
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== 'singlekey-id.com') {
            throw new RuntimeException(__('Le formulaire SingleKey pointe vers un hôte inattendu', __FILE__));
        }
    }

    /**
     * Indique si un hôte appartient aux services Home Connect autorisés.
     *
     * @param string $host Nom DNS.
     * @return bool
     */
    private function hostAllowed($host)
    {
        if (in_array($host, array(
            'singlekey-id.com',
            'api.home-connect.com',
            'api.home-connect.cn',
        ), true)) {
            return true;
        }
        return preg_match(
            '/^prod\.(?:reu|rna|rla|rsg|rcn)\.(?:rest|api)\.homeconnectegw\.(?:com|cn)$/',
            (string) $host
        ) === 1;
    }

    /**
     * Résout une redirection absolue, protocole-relative ou relative.
     *
     * @param string $base URL courante.
     * @param string $location Valeur Location/ReturnUrl.
     * @return string
     */
    private function resolveUrl($base, $location)
    {
        $location = html_entity_decode(trim((string) $location), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (stripos($location, 'hcauth://') === 0 || preg_match('#^https://#i', $location)) {
            return $location;
        }
        $scheme = (string) parse_url($base, PHP_URL_SCHEME);
        $host = (string) parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);
        $authority = $scheme . '://' . $host . ($port ? ':' . $port : '');
        if (strpos($location, '//') === 0) {
            return $scheme . ':' . $location;
        }
        if (strpos($location, '/') === 0) {
            return $authority . $location;
        }
        $path = (string) parse_url($base, PHP_URL_PATH);
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $authority . ($directory !== '' ? $directory : '') . '/' . $location;
    }

    /**
     * Extrait un paramètre d'URL.
     *
     * @param string $url URL.
     * @param string $name Paramètre.
     * @return string
     */
    private function queryParameter($url, $name)
    {
        $parameters = array();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
        return is_scalar($parameters[$name] ?? null) ? (string) $parameters[$name] : '';
    }

    /**
     * Retourne un en-tête sans dépendre de sa casse.
     *
     * @param array<string,mixed> $response Réponse.
     * @param string $name En-tête.
     * @return string
     */
    private function header($response, $name)
    {
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : array();
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, (string) $name) === 0) {
                return trim((string) $value);
            }
        }
        return '';
    }

    /**
     * Indique si un code HTTP est une redirection exploitable.
     *
     * @param int $status Code HTTP.
     * @return bool
     */
    private function isRedirect($status)
    {
        return in_array((int) $status, array(301, 302, 303, 307, 308), true);
    }

    /**
     * Décode les claims utiles d'un JWT sans journaliser le jeton.
     *
     * @param string $token JWT.
     * @return array<string,mixed>
     */
    private function tokenClaims($token)
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3) {
            return array('region' => 'EU');
        }
        $payload = $this->base64UrlDecode($parts[1]);
        $claims = $payload === false ? null : json_decode($payload, true);
        if (!is_array($claims)) {
            return array('region' => 'EU');
        }
        $claims['region'] = strtoupper((string) ($claims['x-reg'] ?? $claims['region'] ?? 'EU'));
        return $claims;
    }

    /**
     * Retourne les services de profils dans l'ordre adapté à la région.
     *
     * @param string $region Région issue du JWT.
     * @return string[]
     */
    private function assetCandidates($region)
    {
        $byRegion = array(
            'EU' => array('https://prod.reu.rest.homeconnectegw.com', 'https://eu.services.home-connect.com'),
            'NA' => array('https://na.services.home-connect.com'),
            'CN' => array('https://cn.services.home-connect.cn'),
        );
        return $byRegion[strtoupper($region)] ?? $byRegion['EU'];
    }

    /**
     * Place une valeur en tête d'une liste sans doublon.
     *
     * @param string $preferred Valeur prioritaire.
     * @param string[] $values Valeurs existantes.
     * @return string[]
     */
    private function prioritize($preferred, $values)
    {
        return array_values(array_unique(array_merge(array($preferred), $values)));
    }

    /**
     * Encode un formulaire selon RFC 3986.
     *
     * @param array<string,mixed> $values Valeurs.
     * @return string
     */
    private function query($values)
    {
        return http_build_query($values, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Encode des octets en Base64 URL sans remplissage.
     *
     * @param string $value Octets.
     * @return string
     */
    private static function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Décode une valeur Base64 URL.
     *
     * @param string $value Valeur.
     * @return string|false
     */
    private function base64UrlDecode($value)
    {
        $value = strtr((string) $value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        return base64_decode($value, true);
    }

    /**
     * Retourne une URL de diagnostic sans paramètres sensibles.
     *
     * @param string $url URL complète.
     * @return string
     */
    private function safeUrlLabel($url)
    {
        return (string) parse_url($url, PHP_URL_HOST) . (string) parse_url($url, PHP_URL_PATH);
    }

    /**
     * Crée un fichier temporaire privé.
     *
     * @param string $prefix Préfixe.
     * @return string
     */
    private function temporaryFile($prefix)
    {
        $path = tempnam($this->workDirectory, '.localhomeconnect-' . $prefix);
        if ($path === false) {
            throw new RuntimeException(__('Création d’un fichier temporaire impossible', __FILE__));
        }
        chmod($path, 0600);
        return $path;
    }

    /**
     * Crée un répertoire privé.
     *
     * @param string $path Répertoire.
     * @return void
     */
    private function ensureDirectory($path)
    {
        if ($path === '' || (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path))) {
            throw new RuntimeException(__('Création du répertoire OAuth impossible', __FILE__));
        }
        @chmod($path, 0700);
    }

    /**
     * Écrit un message non sensible dans le journal.
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
