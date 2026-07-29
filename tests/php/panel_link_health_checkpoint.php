<?php

declare(strict_types=1);

$glpiRoot = getenv('GLPI_ROOT') ?: '/var/www/glpi';
require $glpiRoot . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;

$linkTable = PluginPatchpanelPanelPortLink::getTable();
$portTable = PluginPatchpanelPanelPort::getTable();
$endpointTable = PluginPatchpanelPortEndpoint::getTable();

$portIds = [];
foreach ($DB->request([
    'SELECT' => ['id'],
    'FROM' => $portTable,
    'ORDER' => ['id ASC'],
    'LIMIT' => 3,
]) as $row) {
    $portIds[] = (int) $row['id'];
}
if (count($portIds) < 3) {
    throw new RuntimeException('At least three panel ports are required.');
}

$now = date('Y-m-d H:i:s');
$DB->beginTransaction();
try {
    $DB->insert($linkTable, [
        'panelports_id_a' => $portIds[0],
        'panelports_id_b' => $portIds[1],
        'media_type' => 'other',
        'is_active' => 1,
        'date_creation' => $now,
        'date_mod' => $now,
    ]);
    $DB->insert($linkTable, [
        'panelports_id_a' => $portIds[0],
        'panelports_id_b' => $portIds[2],
        'media_type' => 'other',
        'is_active' => 1,
        'date_creation' => $now,
        'date_mod' => $now,
    ]);
    $DB->insert($endpointTable, [
        'plugin_patchpanel_panelports_id' => $portIds[1],
        'side' => 'rear',
        'itemtype' => \Glpi\Socket::class,
        'items_id' => 999999999,
        'date_creation' => $now,
        'date_mod' => $now,
    ]);

    $checks = PluginPatchpanelHealth::getReport()['integrity'];
    $byLabel = [];
    foreach ($checks as $check) {
        $byLabel[$check['label']] = $check;
    }

    $expectedFailures = [
        'Panel ports used by multiple active links',
        'Panel links conflicting with rear sockets',
    ];
    foreach ($expectedFailures as $label) {
        if (!isset($byLabel[$label]) || $byLabel[$label]['ok']) {
            throw new RuntimeException("Health check did not detect: {$label}");
        }
    }

    echo json_encode([
        'status' => 'passed',
        'ports' => $portIds,
        'detected' => $expectedFailures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $DB->rollback();
}
