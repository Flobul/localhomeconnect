<?php

/**
 * Initialise la configuration du plugin.
 *
 * Les valeurs par défaut sont déclarées dans
 * `core/config/localhomeconnect.config.ini` : Jeedom les applique à
 * l'installation sans qu'elles soient réécrites ici. Seules les opérations
 * qui ne peuvent pas être exprimées par ce fichier reviennent ici.
 *
 * @return void
 */
function localhomeconnect_install()
{
    localhomeconnect::securePrivateStorage();
}

/**
 * Effectue les migrations de configuration lors d'une mise à jour.
 *
 * Aucune valeur par défaut n'est réécrite : le fichier de configuration du
 * plugin reste la référence et les choix de l'utilisateur sont préservés.
 *
 * @return void
 */
function localhomeconnect_update()
{
    localhomeconnect::securePrivateStorage();
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
