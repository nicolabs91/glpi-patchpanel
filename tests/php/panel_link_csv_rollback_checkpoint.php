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
$snapshot = PluginPatchpanelCsvImport::snapshot($portIdA);
$snapshot['endpoints'][PluginPatchpanelPortEndpoint::REAR] = [
    'id' => 0,
    'side' => PluginPatchpanelPortEndpoint::REAR,
    'itemtype' => \Glpi\Socket::class,
    'items_id' => 1,
    'cables_id' => 0,
    'cable_color' => null,
    'cable_label' => '',
    'comment' => null,
];

$DB->beginTransaction();
try {
    PluginPatchpanelPanelPortLink::saveForPorts($portIdA, $portIdB, []);

    $blocked = false;
    try {
        PluginPatchpanelCsvImport::assertRollbackAllowed($portIdA, $snapshot);
    } catch (DomainException $e) {
        $blocked = str_contains($e->getMessage(), 'permanently linked');
    }
    if (!$blocked) {
        throw new RuntimeException('CSV rollback did not reject a rear socket behind an active panel link.');
    }

    $snapshot['endpoints'][PluginPatchpanelPortEndpoint::REAR]['items_id'] = 0;
    PluginPatchpanelCsvImport::assertRollbackAllowed($portIdA, $snapshot);

    echo "Panel-link CSV rollback checkpoint passed for ports {$portIdA} and {$portIdB}.\n";
} finally {
    $DB->rollBack();
}
