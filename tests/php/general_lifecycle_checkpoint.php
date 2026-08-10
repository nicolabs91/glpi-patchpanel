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
$now = date('Y-m-d H:i:s');
$DB->beginTransaction();
try {
    $makePanelPort = static function (string $name) use ($DB, $now): array {
        $DB->insert(PluginPatchpanelPanel::getTable(), [
            'entities_id' => 0, 'is_recursive' => 0, 'name' => $name,
            'locations_id' => 0, 'plugin_patchpanel_panelmodels_id' => 0,
            'port_count' => 1, 'rows' => 1, 'media' => 'copper', 'is_deleted' => 0,
            'date_creation' => $now, 'date_mod' => $now,
        ]);
        $panelId = (int) $DB->insertId();
        $DB->insert(PluginPatchpanelPanelPort::getTable(), [
            'plugin_patchpanel_panels_id' => $panelId, 'number' => 1, 'row' => 1,
            'position' => 1, 'label' => $name . '-P1', 'operational_state' => 'active',
            'media' => 'copper', 'date_creation' => $now, 'date_mod' => $now,
        ]);
        $panelPortId = (int) $DB->insertId();
        $DB->insert(NetworkPort::getTable(), [
            'items_id' => $panelPortId, 'itemtype' => PluginPatchpanelPanelPort::class,
            'entities_id' => 0, 'is_recursive' => 0, 'logical_number' => 1,
            'name' => $name . '-SHADOW', 'instantiation_type' => NetworkPortEthernet::class,
            'is_deleted' => 0, 'is_dynamic' => 0, 'date_creation' => $now, 'date_mod' => $now,
        ]);
        return [$panelId, $panelPortId, (int) $DB->insertId()];
    };
    $makeOwnerPort = static function (string $type, string $name): array {
        $owner = new $type();
        $ownerId = (int) $owner->add(['entities_id' => 0, 'name' => $name, 'is_deleted' => 0]);
        $port = new NetworkPort();
        $portId = (int) $port->add([
            'items_id' => $ownerId, 'itemtype' => $type, 'entities_id' => 0,
            'is_recursive' => 0, 'logical_number' => 1, 'name' => $name . '-P1',
            'instantiation_type' => NetworkPortEthernet::class,
            'is_deleted' => 0, 'is_dynamic' => 0,
        ]);
        if ($ownerId <= 0 || $portId <= 0) {
            throw new RuntimeException('Could not create lifecycle owner fixture.');
        }
        return [$owner, $ownerId, $portId];
    };
    $assertReleased = static function (int $relationId, int $panelPortId, string $message): void {
        if (
            countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $relationId])
            || countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), [
                'plugin_patchpanel_panelports_id' => $panelPortId,
            ])
        ) {
            throw new RuntimeException($message);
        }
    };
    $wired = new NetworkPort_NetworkPort();

    [$panelA, $panelPortA, $shadowA] = $makePanelPort('PP-GENERAL-DELETED-OWNER');
    [$ownerA, $ownerAId, $frontA] = $makeOwnerPort(NetworkEquipment::class, 'PP-GENERAL-DELETED-EQUIPMENT');
    $ownerA->getFromDB($ownerAId);
    $ownerA->update(['id' => $ownerAId, 'is_deleted' => 1]);
    $deletedOwnerRelation = (int) $wired->add([
        'networkports_id_1' => $frontA, 'networkports_id_2' => $shadowA,
    ]);
    if (
        countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $deletedOwnerRelation])
        || countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), [
            'plugin_patchpanel_panelports_id' => $panelPortA,
        ])
    ) {
        throw new RuntimeException('A port owned by an already deleted item was accepted.');
    }

    [$panelB, $panelPortB, $shadowB] = $makePanelPort('PP-GENERAL-COMPUTER');
    [$computer, $computerId, $computerPort] = $makeOwnerPort(Computer::class, 'PP-GENERAL-COMPUTER');
    $computerRelation = (int) $wired->add([
        'networkports_id_1' => $computerPort, 'networkports_id_2' => $shadowB,
    ]);
    $computer->getFromDB($computerId);
    $computer->update(['id' => $computerId, 'is_deleted' => 1]);
    $assertReleased($computerRelation, $panelPortB, 'Computer soft-delete did not release PatchPanel.');

    [$panelC, $panelPortC, $shadowC] = $makePanelPort('PP-GENERAL-PANEL');
    [$ownerC, $ownerCId, $frontC] = $makeOwnerPort(NetworkEquipment::class, 'PP-GENERAL-PANEL-EQUIPMENT');
    $panelRelation = (int) $wired->add([
        'networkports_id_1' => $frontC, 'networkports_id_2' => $shadowC,
    ]);
    $panel = new PluginPatchpanelPanel();
    $panel->getFromDB($panelC);
    $panel->update(['id' => $panelC, 'is_deleted' => 1]);
    $assertReleased($panelRelation, $panelPortC, 'Panel soft-delete did not release its connections.');
    $panel->getFromDB($panelC);
    $panel->update(['id' => $panelC, 'is_deleted' => 0]);
    if (countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), ['plugin_patchpanel_panelports_id' => $panelPortC])) {
        throw new RuntimeException('Panel restore unexpectedly recreated old connections.');
    }

    [$panelD, $panelPortD] = $makePanelPort('PP-GENERAL-SOCKET');
    $socket = new \Glpi\Socket();
    $socketId = (int) $socket->add([
        'name' => 'PP-GENERAL-SOCKET', 'locations_id' => 0,
        'itemtype' => '', 'items_id' => 0, 'networkports_id' => 0,
    ]);
    $DB->insert(PluginPatchpanelPortEndpoint::getTable(), [
        'plugin_patchpanel_panelports_id' => $panelPortD, 'side' => 'rear',
        'itemtype' => \Glpi\Socket::class, 'items_id' => $socketId, 'cables_id' => 0,
        'date_creation' => $now, 'date_mod' => $now,
    ]);
    $socket->getFromDB($socketId);
    $socket->delete(['id' => $socketId], true);
    if (countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), [
        'plugin_patchpanel_panelports_id' => $panelPortD, 'side' => 'rear',
    ])) {
        throw new RuntimeException('Socket purge left a stale rear endpoint.');
    }

    [$panelE, $panelPortE] = $makePanelPort('PP-GENERAL-LEGACY');
    [$legacyComputer, $legacyComputerId, $legacyPort] = $makeOwnerPort(Computer::class, 'PP-GENERAL-LEGACY');
    $DB->update(Computer::getTable(), ['is_deleted' => 1], ['id' => $legacyComputerId]);
    $DB->insert(PluginPatchpanelPortEndpoint::getTable(), [
        'plugin_patchpanel_panelports_id' => $panelPortE, 'side' => 'front',
        'itemtype' => NetworkPort::class, 'items_id' => $legacyPort, 'cables_id' => 0,
        'date_creation' => $now, 'date_mod' => $now,
    ]);
    $route = PluginPatchpanelRoute::buildForPort($panelPortE);
    $panelPort = new PluginPatchpanelPanelPort();
    $panelPort->getFromDB($panelPortE);
    if (empty($route['has_broken_reference']) || empty($route['front']['broken'])) {
        throw new RuntimeException('Route did not fail closed for a legacy deleted generic owner.');
    }
    if ($panelPort->getDisplayStatus() !== 'attention') {
        throw new RuntimeException('Panel grid did not mark a legacy deleted generic owner as broken.');
    }
    $checks = [];
    foreach (PluginPatchpanelHealth::getReport()['integrity'] as $check) {
        $checks[(string) $check['label']] = (bool) $check['ok'];
    }
    if (($checks['Front endpoints owned by deleted or missing items'] ?? true) !== false) {
        throw new RuntimeException('Health missed a legacy endpoint owned by a deleted computer.');
    }

    echo "General lifecycle checkpoint passed.\n";
} finally {
    $DB->rollBack();
}
