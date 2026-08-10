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

$ports = [];
foreach ($DB->request([
    'FROM' => PluginPatchpanelPanelPort::getTable(),
    'ORDER' => ['id ASC'],
]) as $port) {
    $portId = (int) $port['id'];
    if (
        !PluginPatchpanelPanelPortLink::hasActiveLink($portId)
        && !(PluginPatchpanelPortEndpoint::getForPort($portId)[PluginPatchpanelPortEndpoint::REAR] ?? false)
    ) {
        $ports[] = $port;
    }
    if (
        count($ports) === 2
        && (int) $ports[0]['plugin_patchpanel_panels_id']
            !== (int) $ports[1]['plugin_patchpanel_panels_id']
    ) {
        break;
    }
    if (count($ports) === 2) {
        array_pop($ports);
    }
}
if (count($ports) !== 2) {
    throw new RuntimeException('Two free ports on different panels are required.');
}

$portIdA = (int) $ports[0]['id'];
$portIdB = (int) $ports[1]['id'];
$panelIdB = (int) $ports[1]['plugin_patchpanel_panels_id'];
$DB->beginTransaction();
try {
    if (!$DB->update(
        PluginPatchpanelPanel::getTable(),
        ['is_deleted' => 1],
        ['id' => $panelIdB]
    )) {
        throw new RuntimeException('Could not create the deleted-panel fixture.');
    }

    $rejected = false;
    try {
        PluginPatchpanelPanelPortLink::saveForPorts($portIdA, $portIdB, []);
    } catch (DomainException $e) {
        $rejected = true;
    }
    if (!$rejected || PluginPatchpanelPanelPortLink::hasActiveLink($portIdA)) {
        throw new RuntimeException('A panel link was accepted through a deleted parent panel.');
    }

    echo "Panel-link deleted-parent checkpoint passed for ports {$portIdA} and {$portIdB}.\n";
} finally {
    $DB->rollBack();
}
