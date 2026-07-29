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

$portIds = [];
foreach ($DB->request([
    'SELECT' => ['id'],
    'FROM' => PluginPatchpanelPanelPort::getTable(),
    'ORDER' => ['id ASC'],
]) as $port) {
    $portId = (int) $port['id'];
    if (
        !PluginPatchpanelPanelPortLink::hasActiveLink($portId)
        && !(PluginPatchpanelPortEndpoint::getForPort($portId)[PluginPatchpanelPortEndpoint::REAR] ?? false)
    ) {
        $portIds[] = $portId;
    }
    if (count($portIds) === 3) {
        break;
    }
}
if (count($portIds) !== 3) {
    throw new RuntimeException('Three free panel ports are required.');
}

[$portIdA, $portIdB, $portIdC] = $portIds;
$DB->beginTransaction();
try {
    $metadata = [
        'panel_link_cable_label' => 'LIFECYCLE-CHECKPOINT',
        'panel_link_cable_color' => '#0d6efd',
        'panel_link_media_type' => 'fiber-sm',
        'panel_link_length' => '12.5',
        'panel_link_comment' => 'Preserved behind the compact form',
    ];
    if (!PluginPatchpanelPanelPortLink::saveForPorts($portIdA, $portIdB, $metadata)) {
        throw new RuntimeException('Initial symmetric link creation failed.');
    }

    $fromA = PluginPatchpanelPanelPortLink::getForPanelPort($portIdA);
    $fromB = PluginPatchpanelPanelPortLink::getForPanelPort($portIdB);
    if (
        !$fromA
        || !$fromB
        || (int) $fromA['id'] !== (int) $fromB['id']
        || PluginPatchpanelPanelPortLink::getPeerPanelPortId($fromA, $portIdA) !== $portIdB
        || PluginPatchpanelPanelPortLink::getPeerPanelPortId($fromB, $portIdB) !== $portIdA
    ) {
        throw new RuntimeException('The link is not symmetric from both endpoints.');
    }

    // The compact rear-side form submits no metadata fields. Existing metadata
    // must remain intact when its single peer-port selector changes.
    if (!PluginPatchpanelPanelPortLink::saveForPorts($portIdA, $portIdC, [])) {
        throw new RuntimeException('Symmetric peer reassignment failed.');
    }
    $reassigned = PluginPatchpanelPanelPortLink::getForPanelPort($portIdA);
    if (
        !$reassigned
        || PluginPatchpanelPanelPortLink::getPeerPanelPortId($reassigned, $portIdA) !== $portIdC
        || PluginPatchpanelPanelPortLink::hasActiveLink($portIdB)
        || ($reassigned['cable_label'] ?? '') !== 'LIFECYCLE-CHECKPOINT'
        || ($reassigned['cable_color'] ?? '') !== '#0d6efd'
        || ($reassigned['media_type'] ?? '') !== 'fiber-sm'
        || (float) ($reassigned['length'] ?? 0) !== 12.5
        || ($reassigned['comment'] ?? '') !== 'Preserved behind the compact form'
    ) {
        throw new RuntimeException('Peer reassignment lost symmetry, occupancy, or hidden metadata.');
    }

    if (!PluginPatchpanelPanelPortLink::deleteForPanelPort($portIdC)) {
        throw new RuntimeException('Symmetric link deletion failed.');
    }
    if (
        PluginPatchpanelPanelPortLink::hasActiveLink($portIdA)
        || PluginPatchpanelPanelPortLink::hasActiveLink($portIdC)
    ) {
        throw new RuntimeException('Link deletion did not release both endpoints.');
    }

    echo "Panel-link lifecycle checkpoint passed for ports {$portIdA}, {$portIdB}, and {$portIdC}.\n";
} finally {
    $DB->rollBack();
}
