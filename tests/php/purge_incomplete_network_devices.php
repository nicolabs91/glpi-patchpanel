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

$networkEquipmentTable = NetworkEquipment::getTable();
$networkPortTable = NetworkPort::getTable();
$endpointTable = PluginPatchpanelPortEndpoint::getTable();
$socketTable = \Glpi\Socket::getTable();
$sql = "SELECT ne.id, ne.name
        FROM `$networkEquipmentTable` ne
        WHERE ne.is_deleted = 0
          AND ne.is_template = 0
          AND NOT EXISTS (
            SELECT 1
            FROM `$networkPortTable` np
            INNER JOIN `$endpointTable` e
              ON e.side = 'front'
             AND e.itemtype = 'NetworkPort'
             AND e.items_id = np.id
            WHERE np.itemtype = 'NetworkEquipment'
              AND np.items_id = ne.id
              AND np.is_deleted = 0
          )
          AND NOT EXISTS (
            SELECT 1
            FROM `$networkPortTable` np
            INNER JOIN `$socketTable` s
              ON s.networkports_id = np.id
             AND s.itemtype = 'NetworkEquipment'
             AND s.items_id = ne.id
            INNER JOIN `$endpointTable` e
              ON e.side = 'rear'
             AND e.itemtype = 'Glpi\\\\Socket'
             AND e.items_id = s.id
            WHERE np.itemtype = 'NetworkEquipment'
              AND np.items_id = ne.id
              AND np.is_deleted = 0
          )
        ORDER BY ne.id";

$result = $DB->doQuery($sql);
$targets = [];
while ($result && ($row = $result->fetch_assoc())) {
    $targets[] = [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
    ];
}

if (getenv('APPLY_INCOMPLETE_DEVICE_PURGE') !== '1') {
    echo json_encode([
        'mode' => 'preview',
        'count' => count($targets),
        'targets' => $targets,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$DB->beginTransaction();
try {
    foreach ($targets as $target) {
        $device = new NetworkEquipment();
        if (!$device->getFromDB($target['id'])) {
            throw new RuntimeException("Device {$target['id']} disappeared before purge.");
        }
        if (!$device->delete(['id' => $target['id']], true)) {
            throw new RuntimeException("Could not purge device {$target['id']} ({$target['name']}).");
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
    'count' => count($targets),
    'targets' => $targets,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
