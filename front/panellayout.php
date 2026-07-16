<?php

include '../../../inc/includes.php';

Session::checkCentralAccess();
Session::checkRight('networking', READ);

header('Content-Type: application/json');

$panelId = (int) ($_GET['id'] ?? 0);
$rackId = (int) ($_GET['rack_id'] ?? 0);
$panel = new PluginPatchpanelPanel();
if ($panelId <= 0 || !$panel->getFromDB($panelId)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'panel_not_found']);
    exit;
}

$modelId = (int) ($panel->fields['plugin_patchpanel_panelmodels_id'] ?? 0);
$model = new PluginPatchpanelPanelModel();
$requiredUnits = 1;
if ($modelId > 0 && $model->getFromDB($modelId)) {
    $requiredUnits = max(1, (int) ($model->fields['required_units'] ?? 1));
}

$rack = new Rack();
$rackUnits = 0;
if ($rackId > 0 && $rack->getFromDB($rackId)) {
    $rackUnits = max(1, (int) ($rack->fields['number_units'] ?? 0));
}

echo json_encode([
    'ok' => true,
    'id' => $panelId,
    'required_units' => $requiredUnits,
    'rack_units' => $rackUnits,
]);
