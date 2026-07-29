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

$ports = iterator_to_array($DB->request([
    'SELECT' => ['id', 'plugin_patchpanel_panels_id'],
    'FROM' => PluginPatchpanelPanelPort::getTable(),
    'ORDER' => ['id ASC'],
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
    if (
        count($candidates) === 2
        && (int) $candidates[0]['plugin_patchpanel_panels_id']
            !== (int) $candidates[1]['plugin_patchpanel_panels_id']
    ) {
        break;
    }
    if (count($candidates) === 2) {
        array_pop($candidates);
    }
}
if (count($candidates) !== 2) {
    throw new RuntimeException('Two free ports on different panels are required.');
}

$portIdA = (int) $candidates[0]['id'];
$portIdB = (int) $candidates[1]['id'];
$panelIdB = (int) $candidates[1]['plugin_patchpanel_panels_id'];
$DB->beginTransaction();
try {
    PluginPatchpanelPanelPortLink::saveForPorts($portIdA, $portIdB, []);
    $port = new PluginPatchpanelPanelPort();
    if (!$port->getFromDB($portIdA)) {
        throw new RuntimeException('Cleanup fixture port disappeared.');
    }
    $port->cleanDBonPurge();

    if (PluginPatchpanelPanelPortLink::getForPanelPort($portIdA, false) !== null) {
        throw new RuntimeException('Port purge cleanup left the symmetric link behind.');
    }
    $peerAudit = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_plugin_patchpanel_audits',
        'WHERE' => [
            'plugin_patchpanel_panels_id' => $panelIdB,
            'plugin_patchpanel_panelports_id' => $portIdB,
            'action' => 'panel_link_delete',
        ],
        'ORDER' => ['id DESC'],
        'LIMIT' => 1,
    ])->current();
    if (!$peerAudit) {
        throw new RuntimeException('Peer panel did not retain the cleanup audit event.');
    }

    echo "Panel-link cleanup checkpoint passed for ports {$portIdA} and {$portIdB}.\n";
} finally {
    $DB->rollBack();
}
