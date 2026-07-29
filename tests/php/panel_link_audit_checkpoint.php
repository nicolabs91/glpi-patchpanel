<?php

declare(strict_types=1);

$glpiRoot = getenv('GLPI_ROOT') ?: '/var/www/glpi';
require $glpiRoot . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;

$_SESSION['glpiID'] = 2;
$_SESSION['glpiactiveprofile'] = ['networking' => ALLSTANDARDRIGHT];
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactive_entity_recursive'] = 1;
$_SESSION['glpishowallentities'] = 1;

$portTable = PluginPatchpanelPanelPort::getTable();
$panelTable = PluginPatchpanelPanel::getTable();
$auditTable = 'glpi_plugin_patchpanel_audits';
$ports = iterator_to_array($DB->request([
    'SELECT' => [
        $portTable . '.id',
        $portTable . '.plugin_patchpanel_panels_id',
    ],
    'FROM' => $portTable,
    'INNER JOIN' => [
        $panelTable => [
            'FKEY' => [
                $portTable => 'plugin_patchpanel_panels_id',
                $panelTable => 'id',
            ],
        ],
    ],
    'WHERE' => [$panelTable . '.is_deleted' => 0],
    'ORDER' => [$portTable . '.id ASC'],
]));

$candidates = [];
foreach ($ports as $port) {
    $portId = (int) $port['id'];
    if (
        !PluginPatchpanelPanelPortLink::hasActiveLink($portId)
        && !(PluginPatchpanelPortEndpoint::getForPort($portId)[PluginPatchpanelPortEndpoint::REAR] ?? false)
    ) {
        $candidates[] = $port;
    }
    if (count($candidates) === 2) {
        break;
    }
}
if (count($candidates) !== 2) {
    throw new RuntimeException('Two free panel ports are required for the audit fixture.');
}

$portIdA = (int) $candidates[0]['id'];
$portIdB = (int) $candidates[1]['id'];
$auditBaseline = (int) $DB->request([
    'SELECT' => [new QueryExpression('MAX(id) AS max_id')],
    'FROM' => $auditTable,
])->current()['max_id'];
$DB->beginTransaction();
try {
    PluginPatchpanelPanelPortLink::saveForPorts($portIdA, $portIdB, []);
    PluginPatchpanelPanelPortLink::saveForPorts($portIdA, $portIdB, []);
    PluginPatchpanelPanelPortLink::deleteForPanelPort($portIdA);

    $events = iterator_to_array($DB->request([
        'FROM' => $auditTable,
        'WHERE' => [
            ['id' => ['>', $auditBaseline]],
            'plugin_patchpanel_panelports_id' => [$portIdA, $portIdB],
            'action' => ['panel_link_create', 'panel_link_update', 'panel_link_delete'],
        ],
        'ORDER' => ['id DESC'],
        'LIMIT' => 6,
    ]));
    if (count($events) !== 6) {
        throw new RuntimeException('Expected two audit rows for each of create, update and delete.');
    }
    foreach ($events as $event) {
        $snapshot = json_decode($event['after_json'] ?: $event['before_json'], true, 512, JSON_THROW_ON_ERROR);
        foreach (['endpoint_a', 'endpoint_b'] as $endpoint) {
            if (
                empty($snapshot[$endpoint]['panel_name'])
                || empty($snapshot[$endpoint]['port_id'])
                || !array_key_exists('port_number', $snapshot[$endpoint])
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Audit %s snapshot is missing symmetric endpoint context: %s',
                        $event['action'],
                        json_encode($snapshot, JSON_THROW_ON_ERROR)
                    )
                );
            }
        }
    }
    echo "Panel-link audit checkpoint passed for ports {$portIdA} and {$portIdB}.\n";
} finally {
    $DB->rollBack();
}
