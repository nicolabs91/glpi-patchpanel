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
    'ORDER' => ['number DESC', 'id ASC'],
]) as $port) {
    $portId = (int) $port['id'];
    if (
        (int) $port['number'] > 1
        && !PluginPatchpanelPanelPortLink::hasActiveLink($portId)
        && !(PluginPatchpanelPortEndpoint::getForPort($portId)[PluginPatchpanelPortEndpoint::REAR] ?? false)
    ) {
        $ports[] = $port;
    }
    if (count($ports) === 3) {
        break;
    }
}
if (count($ports) !== 3) {
    throw new RuntimeException('Three free non-first panel ports are required.');
}

[$highPort, $peerOne, $peerTwo] = $ports;
$highPortId = (int) $highPort['id'];
$peerOneId = (int) $peerOne['id'];
$peerTwoId = (int) $peerTwo['id'];
$panelId = (int) $highPort['plugin_patchpanel_panels_id'];
$newPortCount = (int) $highPort['number'] - 1;

$DB->beginTransaction();
try {
    if (!PluginPatchpanelPanelPortLink::saveForPorts($highPortId, $peerOneId, [])) {
        throw new RuntimeException('Could not create the shrink-protection fixture link.');
    }

    $panel = new PluginPatchpanelPanel();
    if (!$panel->getFromDB($panelId)) {
        throw new RuntimeException('Fixture panel does not exist.');
    }
    if ($panel->prepareInputForUpdate([
        'id' => $panelId,
        'port_count' => $newPortCount,
    ]) !== false) {
        throw new RuntimeException('Panel shrink was allowed despite an out-of-range rear panel link.');
    }

    // Simulate legacy/corrupt data with one port present in two links. Cleanup
    // must remove every row touching that port, not just the first match.
    [$duplicateA, $duplicateB] = PluginPatchpanelPanelPortLink::normalizePair(
        $highPortId,
        $peerTwoId
    );
    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    if (!$DB->insert(PluginPatchpanelPanelPortLink::getTable(), [
        'panelports_id_a' => $duplicateA,
        'panelports_id_b' => $duplicateB,
        'media_type' => 'other',
        'is_active' => 1,
        'date_creation' => $now,
        'date_mod' => $now,
    ])) {
        throw new RuntimeException('Could not create the duplicate-link cleanup fixture.');
    }

    if (!PluginPatchpanelPanelPortLink::deleteForPanelPort($highPortId)) {
        throw new RuntimeException('Duplicate panel-link cleanup returned failure.');
    }
    $remaining = countElementsInTable(PluginPatchpanelPanelPortLink::getTable(), [
        'OR' => [
            'panelports_id_a' => $highPortId,
            'panelports_id_b' => $highPortId,
        ],
    ]);
    if ($remaining !== 0) {
        throw new RuntimeException("Duplicate panel-link cleanup left {$remaining} row(s) behind.");
    }

    echo "Panel core invariants checkpoint passed for panel {$panelId} and port {$highPortId}.\n";
} finally {
    $DB->rollBack();
}
