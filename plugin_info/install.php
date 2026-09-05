<?php

/**
 * Retourne une valeur de port de démon sûre.
 *
 * @param mixed $value Valeur enregistrée.
 * @return int
 */
function localhomeconnect_normalize_port($value)
{
    $port = (int) $value;
    return $port >= 1024 && $port <= 65535 ? $port : 55043;
}

/**
 * Initialise la configuration du plugin.
 *
 * @return void
 */
function localhomeconnect_install()
{
    localhomeconnect::securePrivateStorage();
    config::save('daemon_port', 55043, 'localhomeconnect');
    config::save('discovery_timeout', 8, 'localhomeconnect');
    config::save('reconnect_interval', 30, 'localhomeconnect');
    config::save('watchdog_interval', 600, 'localhomeconnect');
    config::save('app_name', 'Jeedom LocalHomeConnect', 'localhomeconnect');
    config::save('callback_verify_tls', 1, 'localhomeconnect');
    config::save('homeconnect_username', '', 'localhomeconnect');
    config::save('homeconnect_password', '', 'localhomeconnect');
}

/**
 * Normalise la configuration lors d'une mise à jour.
 *
 * @return void
 */
function localhomeconnect_update()
{
    localhomeconnect::securePrivateStorage();
    config::save(
        'daemon_port',
        localhomeconnect_normalize_port(config::byKey('daemon_port', 'localhomeconnect', 55043)),
        'localhomeconnect'
    );
    foreach (array('discovery_timeout' => 8, 'reconnect_interval' => 30, 'watchdog_interval' => 600) as $key => $default) {
        $value = (int) config::byKey($key, 'localhomeconnect', $default);
        config::save($key, $value > 0 ? $value : $default, 'localhomeconnect');
    }
    if (trim((string) config::byKey('app_name', 'localhomeconnect', '')) === '') {
        config::save('app_name', 'Jeedom LocalHomeConnect', 'localhomeconnect');
    }
    config::save('callback_verify_tls', (int) config::byKey('callback_verify_tls', 'localhomeconnect', 1) === 1 ? 1 : 0, 'localhomeconnect');
    // Réenregistre les secrets existants afin que les installations venant
    // d'une version antérieure bénéficient aussi de $_encryptConfigKey.
    foreach (array('homeconnect_password', 'daemon_token') as $secretKey) {
        $secret = (string) config::byKey($secretKey, 'localhomeconnect', '');
        if ($secret !== '') {
            config::save($secretKey, $secret, 'localhomeconnect');
        }
    }
    foreach (array(
        'profile_client_id',
        'profile_client_secret',
        'profile_scope',
    ) as $obsoleteKey) {
        config::remove($obsoleteKey, 'localhomeconnect');
    }

    // Recharge le code Node mis à jour une seule fois. Les changements de
    // niveau suivants seront transmis à chaud par les hooks postConfig.
    if (class_exists('localhomeconnect')) {
        try {
            if ((string) (localhomeconnect::deamon_info()['state'] ?? 'nok') === 'ok') {
                localhomeconnect::deamon_start();
            }
        } catch (Throwable $exception) {
            log::add('localhomeconnect', 'error', __('Redémarrage du démon après mise à jour impossible :', __FILE__) . ' ' . $exception->getMessage());
        }
    }
}

/**
 * Arrête le démon lors de la suppression du plugin.
 *
 * @return void
 */
function localhomeconnect_remove()
{
    if (class_exists('localhomeconnect')) {
        localhomeconnect::deamon_stop();
    }
}
