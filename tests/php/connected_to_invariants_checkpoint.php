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

$wired = new NetworkPort_NetworkPort();
$now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
$DB->beginTransaction();
try {
    if (!$DB->insert(PluginPatchpanelPanel::getTable(), [
        'entities_id' => 0,
        'is_recursive' => 0,
        'name' => 'PP-CONNECTED-TO-CHECKPOINT-PANEL',
        'locations_id' => 0,
        'plugin_patchpanel_panelmodels_id' => 0,
        'port_count' => 2,
        'rows' => 1,
        'media' => 'copper',
        'is_deleted' => 0,
        'date_creation' => $now,
        'date_mod' => $now,
    ])) {
        throw new RuntimeException('Could not create checkpoint panel.');
    }
    $panelId = (int) $DB->insertId();
    $candidates = [];
    for ($number = 1; $number <= 2; $number++) {
        if (!$DB->insert(PluginPatchpanelPanelPort::getTable(), [
            'plugin_patchpanel_panels_id' => $panelId,
            'number' => $number,
            'row' => 1,
            'position' => $number,
            'label' => sprintf('Checkpoint port %d', $number),
            'operational_state' => 'active',
            'media' => 'copper',
            'date_creation' => $now,
            'date_mod' => $now,
        ])) {
            throw new RuntimeException('Could not create checkpoint panel port.');
        }
        $panelPortId = (int) $DB->insertId();
        if (!$DB->insert(NetworkPort::getTable(), [
            'items_id' => $panelPortId,
            'itemtype' => PluginPatchpanelPanelPort::class,
            'entities_id' => 0,
            'is_recursive' => 0,
            'logical_number' => $number,
            'name' => sprintf('PP-CONNECTED-TO-CHECKPOINT-SHADOW-%d', $number),
            'instantiation_type' => NetworkPortEthernet::class,
            'is_deleted' => 0,
            'is_dynamic' => 0,
            'date_creation' => $now,
            'date_mod' => $now,
        ])) {
            throw new RuntimeException('Could not create checkpoint shadow port.');
        }
        $candidates[] = [
            'shadow_id' => (int) $DB->insertId(),
            'panel_port_id' => $panelPortId,
            'panel_id' => $panelId,
        ];
    }

    if (!$DB->insert(NetworkEquipment::getTable(), [
        'entities_id' => 0,
        'is_recursive' => 0,
        'name' => 'PP-CONNECTED-TO-CHECKPOINT',
        'is_deleted' => 0,
        'is_template' => 0,
        'is_dynamic' => 0,
        'date_creation' => $now,
        'date_mod' => $now,
    ])) {
        throw new RuntimeException('Could not create checkpoint network equipment.');
    }
    $equipmentId = (int) $DB->insertId();
    if (!$DB->insert(NetworkPort::getTable(), [
        'items_id' => $equipmentId,
        'itemtype' => NetworkEquipment::class,
        'entities_id' => 0,
        'is_recursive' => 0,
        'logical_number' => 1,
        'name' => 'PP-CONNECTED-TO-CHECKPOINT-PORT',
        'instantiation_type' => NetworkPortEthernet::class,
        'is_deleted' => 0,
        'is_dynamic' => 0,
        'date_creation' => $now,
        'date_mod' => $now,
    ])) {
        throw new RuntimeException('Could not create checkpoint network port.');
    }
    $frontPortId = (int) $DB->insertId();

    $shadowLinkId = (int) $wired->add([
        'networkports_id_1' => $candidates[0]['shadow_id'],
        'networkports_id_2' => $candidates[1]['shadow_id'],
    ]);
    if ($shadowLinkId <= 0 || countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $shadowLinkId])) {
        throw new RuntimeException('A shadow-to-shadow Connected to relation survived validation.');
    }

    $DB->update(PluginPatchpanelPanel::getTable(), ['is_deleted' => 1], ['id' => $candidates[0]['panel_id']]);
    $deletedPanelLinkId = (int) $wired->add([
        'networkports_id_1' => $frontPortId,
        'networkports_id_2' => $candidates[0]['shadow_id'],
    ]);
    if (
        $deletedPanelLinkId <= 0
        || countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $deletedPanelLinkId])
        || countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), [
            'plugin_patchpanel_panelports_id' => $candidates[0]['panel_port_id'],
            'side' => PluginPatchpanelPortEndpoint::FRONT,
        ])
    ) {
        throw new RuntimeException('A Connected to relation through a deleted panel survived validation.');
    }

    $DB->update(PluginPatchpanelPanel::getTable(), ['is_deleted' => 0], ['id' => $candidates[0]['panel_id']]);
    $validLinkId = (int) $wired->add([
        'networkports_id_1' => $frontPortId,
        'networkports_id_2' => $candidates[0]['shadow_id'],
    ]);
    if (
        $validLinkId <= 0
        || !countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $validLinkId])
        || !countElementsInTable(PluginPatchpanelPortEndpoint::getTable(), [
            'plugin_patchpanel_panelports_id' => $candidates[0]['panel_port_id'],
            'side' => PluginPatchpanelPortEndpoint::FRONT,
            'itemtype' => NetworkPort::class,
            'items_id' => $frontPortId,
        ])
    ) {
        throw new RuntimeException('A valid Connected to relation did not synchronize to PatchPanel.');
    }

    // Seed legacy-invalid data without hooks and prove Health reports both forms.
    $DB->insert(NetworkPort_NetworkPort::getTable(), [
        'networkports_id_1' => $candidates[0]['shadow_id'],
        'networkports_id_2' => $candidates[1]['shadow_id'],
    ]);
    $DB->update(PluginPatchpanelPanel::getTable(), ['is_deleted' => 1], ['id' => $candidates[0]['panel_id']]);
    $checks = [];
    foreach (PluginPatchpanelHealth::getReport()['integrity'] as $check) {
        $checks[(string) $check['label']] = (bool) $check['ok'];
    }
    if (($checks['Invalid native links involving PatchPanel ports'] ?? true) !== false) {
        throw new RuntimeException('Health missed an invalid native PatchPanel relation.');
    }
    if (($checks['Front endpoints attached to a deleted panel'] ?? true) !== false) {
        throw new RuntimeException('Health missed a front endpoint under a deleted panel.');
    }

    echo "Connected-to invariants checkpoint passed.\n";
} finally {
    $DB->rollBack();
}
