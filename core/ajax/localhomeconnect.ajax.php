<?php

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    require_once dirname(__FILE__) . '/../class/localhomeconnect.class.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();
    $action = (string) init('action');

    switch ($action) {
        case 'importProfiles':
            if (!isset($_FILES['profile'])) {
                throw new InvalidArgumentException(__('Sélectionnez une archive ZIP de profils Home Connect', __FILE__));
            }
            if ((int) $_FILES['profile']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(__('Le transfert du profil a échoué', __FILE__));
            }
            if (!is_uploaded_file($_FILES['profile']['tmp_name'])) {
                throw new InvalidArgumentException(__('Le fichier transmis n’est pas valide', __FILE__));
            }
            ajax::success(localhomeconnect::importProfiles($_FILES['profile']['tmp_name']));
            break;
        case 'profiles':
            ajax::success(localhomeconnect::profiles());
            break;
        case 'removeProfile':
            ajax::success(localhomeconnect::removeProfile((string) init('profileId')));
            break;
        case 'downloadProfilesFromHomeconnect':
            @set_time_limit(300);
            try {
                ajax::success(array(
                    'browserRequired' => false,
                    'profiles' => localhomeconnect::downloadProfilesFromHomeconnect(),
                ));
            } catch (LocalHomeConnectBrowserRequiredException $exception) {
                ajax::success(array(
                    'browserRequired' => true,
                    'message' => displayException($exception),
                ));
            }
            break;
        case 'beginBrowserProfileAuthorization':
            ajax::success(localhomeconnect::beginBrowserProfileAuthorization());
            break;
        case 'completeBrowserProfileAuthorization':
            @set_time_limit(300);
            ajax::success(localhomeconnect::completeBrowserProfileAuthorization(
                (string) init('flowId'),
                (string) init('redirectUrl')
            ));
            break;
        case 'synchronize':
            ajax::success(localhomeconnect::synchronize());
            break;
        case 'refresh':
            ajax::success(localhomeconnect::refreshEquipment((int) init('id')));
            break;
        case 'testCommunication':
            ajax::success(localhomeconnect::testCommunication((int) init('id')));
            break;
        default:
            throw new InvalidArgumentException(__('Aucune méthode correspondante : ', __FILE__) . $action);
    }
} catch (Throwable $exception) {
    ajax::error(displayException($exception), $exception->getCode());
}
