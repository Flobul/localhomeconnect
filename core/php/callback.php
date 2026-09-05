<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../class/localhomeconnect.class.php';

header('Content-Type: application/json; charset=utf-8');

$apiKey = (string) ($_SERVER['HTTP_X_JEEDOM_APIKEY'] ?? init('apikey'));
if (!jeedom::apiAccess($apiKey, 'localhomeconnect')) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'error' => __('Accès refusé', __FILE__)));
    exit;
}

try {
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 5242880) {
        http_response_code(413);
        throw new RuntimeException(__('Événement trop volumineux', __FILE__));
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        throw new InvalidArgumentException(__('Corps JSON invalide', __FILE__));
    }
    localhomeconnect::handleDaemonEvent($payload);
    echo json_encode(array('success' => true));
} catch (Throwable $exception) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    log::add('localhomeconnect', 'error', displayException($exception));
    echo json_encode(array('success' => false, 'error' => displayException($exception)));
}
