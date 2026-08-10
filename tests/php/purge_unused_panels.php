<?php

declare(strict_types=1);

$glpiRoot = getenv('GLPI_ROOT') ?: '/var/www/glpi';
require $glpiRoot . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;

$_SESSION['glpiID'] = 2;
$_SESSION['glpiname'] = 'glpi';
$_SESSION['glpiactiveprofile'] = [
    'networking' => ALLSTANDARDRIGHT,
    'config' => ALLSTANDARDRIGHT,
];
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactive_entity_recursive'] = 1;
$_SESSION['glpishowallentities'] = 1;

$preservedNames = [];
for ($floor = 1; $floor <= 5; $floor++) {
    $preservedNames[] = sprintf('HTL-PP-L%d-01', $floor);
}

$panelTargets = [];
foreach ($DB->request([
    'FROM' => PluginPatchpanelPanel::getTable(),
    'WHERE' => [
        'is_deleted' => 0,
        'NOT' => ['name' => $preservedNames],
    ],
    'ORDER' => ['id ASC'],
]) as $panel) {
    $panelTargets[] = [
        'id' => (int) $panel['id'],
        'name' => trim((string) $panel['name']) ?: '(naamloos)',
    ];
}

if (getenv('APPLY_UNUSED_PANEL_PURGE') !== '1') {
    echo json_encode([
        'mode' => 'preview',
        'panel_count' => count($panelTargets),
        'panels' => $panelTargets,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$DB->beginTransaction();
try {
    foreach ($panelTargets as $target) {
        $panel = new PluginPatchpanelPanel();
        if (!$panel->getFromDB($target['id'])) {
            throw new RuntimeException("Panel {$target['id']} disappeared before purge.");
        }
        if (!$panel->delete(['id' => $target['id']], true)) {
            throw new RuntimeException("Could not purge panel {$target['id']} ({$target['name']}).");
        }
        $DB->delete('glpi_items_racks', [
            'itemtype' => PluginPatchpanelPanel::class,
            'items_id' => $target['id'],
        ]);
    }

    // A complete device route must cross a panel: the opposite side is a
    // device-bound rear socket, or a panel-to-panel peer with a front port.
    $deviceTable = NetworkEquipment::getTable();
    $networkPortTable = NetworkPort::getTable();
    $socketTable = \Glpi\Socket::getTable();
    $endpointTable = PluginPatchpanelPortEndpoint::getTable();
    $linkTable = PluginPatchpanelPanelPortLink::getTable();
    $sql = "SELECT ne.id, ne.name
            FROM `$deviceTable` ne
            WHERE ne.is_deleted = 0
              AND ne.is_template = 0
              AND NOT EXISTS (
                SELECT 1
                FROM `$networkPortTable` np
                INNER JOIN `$socketTable` s
                  ON s.networkports_id = np.id
                 AND s.itemtype = 'NetworkEquipment'
                 AND s.items_id = ne.id
                INNER JOIN `$endpointTable` rear
                  ON rear.side = 'rear'
                 AND rear.itemtype = 'Glpi\\\\Socket'
                 AND rear.items_id = s.id
                WHERE np.itemtype = 'NetworkEquipment'
                  AND np.items_id = ne.id
                  AND np.is_deleted = 0
                  AND (
                    EXISTS (
                      SELECT 1 FROM `$endpointTable` front
                      WHERE front.plugin_patchpanel_panelports_id = rear.plugin_patchpanel_panelports_id
                        AND front.side = 'front'
                        AND front.itemtype = 'NetworkPort'
                    )
                    OR EXISTS (
                      SELECT 1 FROM `$linkTable` l
                      INNER JOIN `$endpointTable` peer_front
                        ON peer_front.side = 'front'
                       AND peer_front.itemtype = 'NetworkPort'
                       AND peer_front.plugin_patchpanel_panelports_id = CASE
                         WHEN l.panelports_id_a = rear.plugin_patchpanel_panelports_id THEN l.panelports_id_b
                         ELSE l.panelports_id_a
                       END
                      WHERE l.is_active = 1
                        AND rear.plugin_patchpanel_panelports_id IN (l.panelports_id_a, l.panelports_id_b)
                    )
                  )
              )
              AND NOT EXISTS (
                SELECT 1
                FROM `$networkPortTable` np
                INNER JOIN `$endpointTable` front
                  ON front.side = 'front'
                 AND front.itemtype = 'NetworkPort'
                 AND front.items_id = np.id
                WHERE np.itemtype = 'NetworkEquipment'
                  AND np.items_id = ne.id
                  AND np.is_deleted = 0
                  AND (
                    EXISTS (
                      SELECT 1 FROM `$endpointTable` rear
                      INNER JOIN `$socketTable` s
                        ON s.id = rear.items_id
                       AND s.networkports_id > 0
                       AND s.itemtype = 'NetworkEquipment'
                       AND s.items_id > 0
                      WHERE rear.plugin_patchpanel_panelports_id = front.plugin_patchpanel_panelports_id
                        AND rear.side = 'rear'
                        AND rear.itemtype = 'Glpi\\\\Socket'
                    )
                    OR EXISTS (
                      SELECT 1 FROM `$linkTable` l
                      INNER JOIN `$endpointTable` peer_front
                        ON peer_front.side = 'front'
                       AND peer_front.itemtype = 'NetworkPort'
                       AND peer_front.plugin_patchpanel_panelports_id = CASE
                         WHEN l.panelports_id_a = front.plugin_patchpanel_panelports_id THEN l.panelports_id_b
                         ELSE l.panelports_id_a
                       END
                      WHERE l.is_active = 1
                        AND front.plugin_patchpanel_panelports_id IN (l.panelports_id_a, l.panelports_id_b)
                    )
                  )
              )
            ORDER BY ne.id";

    $incompleteDevices = [];
    $result = $DB->doQuery($sql);
    while ($result && ($row = $result->fetch_assoc())) {
        $incompleteDevices[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ];
    }
    foreach ($incompleteDevices as $target) {
        $device = new NetworkEquipment();
        if (!$device->getFromDB($target['id']) || !$device->delete(['id' => $target['id']], true)) {
            throw new RuntimeException("Could not purge newly incomplete device {$target['id']}.");
        }
    }

    $DB->commit();
} catch (Throwable $e) {
    $DB->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo json_encode([
    'mode' => 'applied',
    'panel_count' => count($panelTargets),
    'panels' => $panelTargets,
    'newly_incomplete_device_count' => count($incompleteDevices),
    'newly_incomplete_devices' => $incompleteDevices,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
