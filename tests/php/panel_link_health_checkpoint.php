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

$now = date('Y-m-d H:i:s');
$DB->beginTransaction();
try {
    if (!$DB->insert(PluginPatchpanelPanel::getTable(), [
        'entities_id' => 0,
        'is_recursive' => 0,
        'name' => 'PP-HEALTH-CHECKPOINT-PANEL',
        'locations_id' => 0,
        'plugin_patchpanel_panelmodels_id' => 0,
        'port_count' => 3,
        'rows' => 1,
        'media' => 'copper',
        'is_deleted' => 0,
        'date_creation' => $now,
        'date_mod' => $now,
    ])) {
        throw new RuntimeException('Could not create the Health checkpoint panel.');
    }
    $panelId = (int) $DB->insertId();
    $portIds = [];
    for ($number = 1; $number <= 3; $number++) {
        if (!$DB->insert($portTable, [
            'plugin_patchpanel_panels_id' => $panelId,
            'number' => $number,
            'row' => 1,
            'position' => $number,
            'label' => sprintf('Health checkpoint port %d', $number),
            'operational_state' => 'active',
            'media' => 'copper',
            'date_creation' => $now,
            'date_mod' => $now,
        ])) {
            throw new RuntimeException('Could not create a Health checkpoint port.');
        }
        $portIds[] = (int) $DB->insertId();
    }

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
