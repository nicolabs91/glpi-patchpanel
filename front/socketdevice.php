<?php

include '../../../inc/includes.php';

Session::checkCentralAccess();

header('Content-Type: application/json');

$socketId = (int) ($_REQUEST['id'] ?? 0);
$socket = new \Glpi\Socket();
if ($socketId <= 0 || !$socket->getFromDB($socketId)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'socket_not_found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkRight('networking', UPDATE);
    Session::checkCSRF($_POST);

    $ok = PluginPatchpanelPortEndpoint::disconnectSocketDevice($socketId);
    echo json_encode(['ok' => $ok]);
    exit;
}

Session::checkRight('networking', READ);
$stale = (string) ($socket->fields['itemtype'] ?? '') !== ''
    && (int) ($socket->fields['items_id'] ?? 0) > 0
    && (int) ($socket->fields['networkports_id'] ?? 0) <= 0;

echo json_encode([
    'ok' => true,
    'id' => $socketId,
    'stale' => $stale,
]);
