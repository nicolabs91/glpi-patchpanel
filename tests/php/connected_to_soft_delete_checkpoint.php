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

$now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
$DB->beginTransaction();
try {
    $DB->insert(PluginPatchpanelPanel::getTable(), [
        'entities_id' => 0, 'is_recursive' => 0,
        'name' => 'PP-SOFT-DELETE-CHECKPOINT-PANEL',
        'locations_id' => 0, 'plugin_patchpanel_panelmodels_id' => 0,
        'port_count' => 2, 'rows' => 1, 'media' => 'copper', 'is_deleted' => 0,
        'date_creation' => $now, 'date_mod' => $now,
    ]);
    $panelId = (int) $DB->insertId();

    $panelPorts = [];
    for ($number = 1; $number <= 2; $number++) {
        $DB->insert(PluginPatchpanelPanelPort::getTable(), [
            'plugin_patchpanel_panels_id' => $panelId,
            'number' => $number, 'row' => 1, 'position' => $number,
            'label' => 'Soft-delete checkpoint ' . $number,
            'operational_state' => 'active', 'media' => 'copper',
            'date_creation' => $now, 'date_mod' => $now,
        ]);
        $panelPortId = (int) $DB->insertId();
        $DB->insert(NetworkPort::getTable(), [
            'items_id' => $panelPortId,
            'itemtype' => PluginPatchpanelPanelPort::class,
            'entities_id' => 0, 'is_recursive' => 0,
            'logical_number' => $number,
            'name' => 'PP-SOFT-DELETE-SHADOW-' . $number,
            'instantiation_type' => NetworkPortEthernet::class,
            'is_deleted' => 0, 'is_dynamic' => 0,
            'date_creation' => $now, 'date_mod' => $now,
        ]);
        $panelPorts[] = ['id' => $panelPortId, 'shadow' => (int) $DB->insertId()];
    }

    $devices = [];
    foreach (['A', 'B', 'C'] as $suffix) {
        $equipment = new NetworkEquipment();
        $equipmentId = (int) $equipment->add([
            'entities_id' => 0, 'is_recursive' => 0,
            'name' => 'PP-SOFT-DELETE-DEVICE-' . $suffix,
            'is_deleted' => 0, 'is_template' => 0, 'is_dynamic' => 0,
        ]);
        if ($equipmentId <= 0) {
            throw new RuntimeException('Could not create checkpoint network equipment ' . $suffix . '.');
        }
        $port = new NetworkPort();
        $portId = (int) $port->add([
            'items_id' => $equipmentId, 'itemtype' => NetworkEquipment::class,
            'entities_id' => 0, 'is_recursive' => 0, 'logical_number' => 1,
            'name' => 'PP-SOFT-DELETE-PORT-' . $suffix,
            'instantiation_type' => NetworkPortEthernet::class,
            'is_deleted' => 0, 'is_dynamic' => 0,
        ]);
        if ($portId <= 0) {
            throw new RuntimeException('Could not create checkpoint network port ' . $suffix . '.');
        }
        $devices[$suffix] = ['equipment' => $equipmentId, 'port' => $portId];
    }

    $unrelatedPorts = [];
    foreach (['A', 'B'] as $suffix) {
        $port = new NetworkPort();
        $unrelatedPorts[$suffix] = (int) $port->add([
            'items_id' => $devices[$suffix]['equipment'],
            'itemtype' => NetworkEquipment::class,
            'entities_id' => 0, 'is_recursive' => 0, 'logical_number' => 2,
            'name' => 'PP-SOFT-DELETE-UNRELATED-' . $suffix,
            'instantiation_type' => NetworkPortEthernet::class,
            'is_deleted' => 0, 'is_dynamic' => 0,
        ]);
    }

    $wired = new NetworkPort_NetworkPort();
    $unrelatedLinkId = (int) $wired->add([
        'networkports_id_1' => $unrelatedPorts['A'],
        'networkports_id_2' => $unrelatedPorts['B'],
    ]);
    if ($unrelatedLinkId <= 0) {
        throw new RuntimeException('Could not create unrelated native checkpoint relation.');
    }
    $linkA = (int) $wired->add([
        'networkports_id_1' => $devices['A']['port'],
        'networkports_id_2' => $panelPorts[0]['shadow'],
    ]);
    if ($linkA <= 0 || !countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), [
        'plugin_patchpanel_panelports_id' => $panelPorts[0]['id'],
        'side' => PluginPatchpanelPortEndpoint::FRONT,
        'items_id' => $devices['A']['port'],
    ])) {
        throw new RuntimeException('Initial Connected to relation did not synchronize.');
    }

    $equipmentA = new NetworkEquipment();
    $equipmentA->getFromDB($devices['A']['equipment']);
    if (!$equipmentA->update(['id' => $devices['A']['equipment'], 'is_deleted' => 1])) {
        throw new RuntimeException('Could not soft-delete checkpoint device A.');
    }
    if (
        countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $linkA])
        || countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), [
            'plugin_patchpanel_panelports_id' => $panelPorts[0]['id'],
            'side' => PluginPatchpanelPortEndpoint::FRONT,
        ])
    ) {
        throw new RuntimeException('Soft-deleting a device did not release its PatchPanel connection.');
    }
    if (!countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $unrelatedLinkId])) {
        throw new RuntimeException('Soft-delete cleanup removed an unrelated native GLPI relation.');
    }

    $equipmentA->getFromDB($devices['A']['equipment']);
    $equipmentA->update(['id' => $devices['A']['equipment'], 'is_deleted' => 0]);
    if (countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), [
        'plugin_patchpanel_panelports_id' => $panelPorts[0]['id'],
        'side' => PluginPatchpanelPortEndpoint::FRONT,
    ])) {
        throw new RuntimeException('Restoring a device unexpectedly recreated its old connection.');
    }

    $linkB = (int) $wired->add([
        'networkports_id_1' => $devices['B']['port'],
        'networkports_id_2' => $panelPorts[0]['shadow'],
    ]);
    if ($linkB <= 0 || !countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $linkB])) {
        throw new RuntimeException('Replacement device could not use the released panel port.');
    }
    $linkC = (int) $wired->add([
        'networkports_id_1' => $devices['C']['port'],
        'networkports_id_2' => $panelPorts[0]['shadow'],
    ]);
    if ($linkC > 0 && countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $linkC])) {
        throw new RuntimeException('An occupied panel port accepted a second device.');
    }

    $portB = new NetworkPort();
    $portB->getFromDB($devices['B']['port']);
    if (!$portB->update(['id' => $devices['B']['port'], 'is_deleted' => 1])) {
        throw new RuntimeException('Could not soft-delete checkpoint network port B.');
    }
    if (
        countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $linkB])
        || countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), [
            'plugin_patchpanel_panelports_id' => $panelPorts[0]['id'],
            'side' => PluginPatchpanelPortEndpoint::FRONT,
        ])
    ) {
        throw new RuntimeException('Soft-deleting a network port did not release its PatchPanel connection.');
    }

    // Seed a legacy stale endpoint without hooks: Health and Route must fail closed.
    $DB->update(NetworkEquipment::getTable(), ['is_deleted' => 1], ['id' => $devices['A']['equipment']]);
    $DB->insert(PluginPatchpanelPortEndpoint::getTable(), [
        'plugin_patchpanel_panelports_id' => $panelPorts[1]['id'],
        'side' => PluginPatchpanelPortEndpoint::FRONT,
        'itemtype' => NetworkPort::class,
        'items_id' => $devices['A']['port'],
        'cables_id' => 0,
        'date_creation' => $now, 'date_mod' => $now,
    ]);
    $route = PluginPatchpanelRoute::buildForPort($panelPorts[1]['id']);
    if (empty($route['has_broken_reference']) || empty($route['front']['broken'])) {
        throw new RuntimeException('Route did not mark a front port owned by a deleted device as broken.');
    }
    $checks = [];
    foreach (PluginPatchpanelHealth::getReport()['integrity'] as $check) {
        $checks[(string) $check['label']] = (bool) $check['ok'];
    }
    if (($checks['Front endpoints owned by deleted or missing items'] ?? true) !== false) {
        throw new RuntimeException('Health missed a front endpoint owned by a deleted device.');
    }

    echo "Connected-to soft-delete checkpoint passed.\n";
} finally {
    $DB->rollBack();
}
