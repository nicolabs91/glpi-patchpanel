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

function findOne(string $table, array $where): array
{
    global $DB;
    return $DB->request([
        'FROM' => $table,
        'WHERE' => $where,
        'LIMIT' => 1,
    ])->current() ?: [];
}

function ensureNetworkPort(int $equipmentId, int $logicalNumber, string $name): int
{
    $existing = findOne(NetworkPort::getTable(), [
        'itemtype' => NetworkEquipment::class,
        'items_id' => $equipmentId,
        'logical_number' => $logicalNumber,
        'is_deleted' => 0,
    ]);
    if ($existing) {
        if (trim((string) $existing['name']) !== $name) {
            $port = new NetworkPort();
            if (!$port->update([
                'id' => (int) $existing['id'],
                'name' => $name,
            ])) {
                throw new RuntimeException("Could not rename network port {$existing['id']} to {$name}.");
            }
        }
        return (int) $existing['id'];
    }

    $port = new NetworkPort();
    $id = (int) $port->add([
        'itemtype' => NetworkEquipment::class,
        'items_id' => $equipmentId,
        'entities_id' => 0,
        'is_recursive' => 0,
        'logical_number' => $logicalNumber,
        'name' => $name,
        'instantiation_type' => NetworkPortEthernet::class,
        'is_deleted' => 0,
        'is_dynamic' => 0,
    ]);
    if ($id <= 0) {
        throw new RuntimeException("Could not add network port {$name} to equipment {$equipmentId}.");
    }
    return $id;
}

function getEquipment(string $name): array
{
    $equipment = findOne(NetworkEquipment::getTable(), [
        'name' => $name,
        'is_deleted' => 0,
        'is_template' => 0,
    ]);
    if (!$equipment) {
        throw new RuntimeException("Network equipment {$name} does not exist.");
    }
    return $equipment;
}

function getEquipmentPort(string $name, int $logicalNumber): int
{
    $equipment = getEquipment($name);
    $port = findOne(NetworkPort::getTable(), [
        'itemtype' => NetworkEquipment::class,
        'items_id' => (int) $equipment['id'],
        'logical_number' => $logicalNumber,
        'is_deleted' => 0,
    ]);
    if (!$port) {
        throw new RuntimeException("Port {$logicalNumber} does not exist on {$name}.");
    }
    return (int) $port['id'];
}

function ensureSocket(string $name, int $locationId, int $equipmentId, int $networkPortId, int $position): int
{
    $existing = findOne(\Glpi\Socket::getTable(), [
        'name' => $name,
        'locations_id' => $locationId,
    ]);
    if (!$existing) {
        $existing = findOne(\Glpi\Socket::getTable(), [
            'itemtype' => NetworkEquipment::class,
            'items_id' => $equipmentId,
            'networkports_id' => $networkPortId,
        ]);
    }
    if ($existing) {
        $socket = new \Glpi\Socket();
        if (!$socket->getFromDB((int) $existing['id'])) {
            throw new RuntimeException("Socket {$name} could not be loaded.");
        }
        if (!$socket->update([
            'id' => (int) $existing['id'],
            'name' => $name,
            'locations_id' => $locationId,
            'position' => $position,
            'itemtype' => NetworkEquipment::class,
            'items_id' => $equipmentId,
            'networkports_id' => $networkPortId,
        ])) {
            throw new RuntimeException("Socket {$name} could not be updated.");
        }
        return (int) $existing['id'];
    }

    $socket = new \Glpi\Socket();
    $id = (int) $socket->add([
        'name' => $name,
        'position' => $position,
        'locations_id' => $locationId,
        'socketmodels_id' => 0,
        'wiring_side' => 1,
        'itemtype' => NetworkEquipment::class,
        'items_id' => $equipmentId,
        'networkports_id' => $networkPortId,
    ]);
    if ($id <= 0) {
        throw new RuntimeException("Could not create socket {$name}.");
    }
    return $id;
}

function ensurePanel(int $floor): int
{
    $name = sprintf('HTL-PP-L%d-01', $floor);
    $existing = findOne(PluginPatchpanelPanel::getTable(), [
        'name' => $name,
        'is_deleted' => 0,
    ]);
    if ($existing) {
        return (int) $existing['id'];
    }

    $panel = new PluginPatchpanelPanel();
    $id = (int) $panel->add([
        'entities_id' => 0,
        'is_recursive' => 0,
        'name' => $name,
        'locations_id' => $floor,
        'plugin_patchpanel_panelmodels_id' => 0,
        'port_count' => 48,
        'rows' => 2,
        'media' => 'copper',
        'comment' => 'Hotel access and backbone topology',
    ]);
    if ($id <= 0) {
        throw new RuntimeException("Could not create panel {$name}.");
    }

    $rackId = (($floor - 1) * 2) + 1;
    $position = $floor === 1 ? 40 : 41;
    $rackItem = new Item_Rack();
    if (!(int) $rackItem->add([
        'racks_id' => $rackId,
        'itemtype' => PluginPatchpanelPanel::class,
        'items_id' => $id,
        'position' => $position,
        'orientation' => 0,
        'hpos' => 0,
        'is_reserved' => 0,
    ])) {
        throw new RuntimeException("Could not place {$name} in rack {$rackId}.");
    }
    return $id;
}

function getPanelPort(int $panelId, int $number): int
{
    $port = findOne(PluginPatchpanelPanelPort::getTable(), [
        'plugin_patchpanel_panels_id' => $panelId,
        'number' => $number,
    ]);
    if (!$port) {
        throw new RuntimeException("Panel {$panelId} has no port {$number}.");
    }
    return (int) $port['id'];
}

function connectPanelPort(int $panelPortId, int $rearSocketId, int $frontNetworkPortId): void
{
    if (!PluginPatchpanelPortEndpoint::saveForPort($panelPortId, [
        'rear_items_id' => $rearSocketId,
        'front_items_id' => $frontNetworkPortId,
        'front_cable_color' => '',
        'front_cables_id' => 0,
    ], false)) {
        throw new RuntimeException("Could not connect panel port {$panelPortId}.");
    }
}

$summary = [
    'network_ports_created' => 0,
    'panels_created' => 0,
    'sockets_created_or_updated' => 0,
    'access_routes_connected' => 0,
    'infrastructure_routes_connected' => 0,
    'panel_links_created' => 0,
];

$DB->beginTransaction();
try {
    foreach ($DB->request([
        'FROM' => NetworkEquipment::getTable(),
        'WHERE' => ['is_deleted' => 0, 'is_template' => 0],
        'ORDER' => ['id ASC'],
    ]) as $equipment) {
        $equipmentId = (int) $equipment['id'];
        $typeId = (int) $equipment['networkequipmenttypes_id'];
        $existingCount = countElementsInTable(NetworkPort::getTable(), [
            'itemtype' => NetworkEquipment::class,
            'items_id' => $equipmentId,
            'is_deleted' => 0,
        ]);

        $definitions = [];
        if ($existingCount === 0 && $typeId === 1) {
            $definitions[1] = 'LAN';
        } elseif ($existingCount === 0 && $typeId === 2) {
            for ($number = 1; $number <= 48; $number++) {
                $definitions[$number] = sprintf('Gi1/0/%d', $number);
            }
            for ($number = 49; $number <= 52; $number++) {
                $definitions[$number] = sprintf('Te1/1/%d', $number - 48);
            }
        } elseif ($existingCount === 0 && $typeId === 3) {
            for ($number = 1; $number <= 12; $number++) {
                $definitions[$number] = sprintf('port%d', $number);
            }
            $definitions += [13 => 'sfp1', 14 => 'sfp2', 15 => 'sfp+1', 16 => 'sfp+2'];
        } elseif ($existingCount === 0 && $typeId === 4) {
            for ($number = 1; $number <= 12; $number++) {
                $definitions[$number] = sprintf('sfp-sfpplus%d', $number);
            }
            $definitions += [13 => 'sfp28-1', 14 => 'sfp28-2', 15 => 'mgmt'];
        } elseif ($existingCount === 0) {
            $definitions[1] = 'uplink';
        }

        foreach ($definitions as $number => $name) {
            ensureNetworkPort($equipmentId, (int) $number, $name);
            $summary['network_ports_created']++;
        }

    }

    $panels = [];
    for ($floor = 1; $floor <= 5; $floor++) {
        $before = countElementsInTable(PluginPatchpanelPanel::getTable(), [
            'name' => sprintf('HTL-PP-L%d-01', $floor),
            'is_deleted' => 0,
        ]);
        $panels[$floor] = ensurePanel($floor);
        if (!$before) {
            $summary['panels_created']++;
        }

        for ($apNumber = 1; $apNumber <= 20; $apNumber++) {
            $apName = sprintf('HTL-AP-L%d-%02d', $floor, $apNumber);
            $ap = getEquipment($apName);
            $apPortId = getEquipmentPort($apName, 1);
            $socketId = ensureSocket(
                sprintf('HTL-L%d-AP-%02d-DROP', $floor, $apNumber),
                $floor,
                (int) $ap['id'],
                $apPortId,
                $apNumber
            );
            $summary['sockets_created_or_updated']++;

            $switchNumber = (int) ceil($apNumber / 3);
            $switchPortNumber = (($apNumber - 1) % 3) + 1;
            $switchPortId = getEquipmentPort(
                sprintf('HTL-SW-L%d-%02d', $floor, $switchNumber),
                $switchPortNumber
            );
            connectPanelPort(getPanelPort($panels[$floor], $apNumber), $socketId, $switchPortId);
            $summary['access_routes_connected']++;
        }

        $routerName = sprintf('HTL-RT-L%d', $floor);
        $router = getEquipment($routerName);
        $routerSocket = ensureSocket(
            sprintf('HTL-L%d-ROUTER-LAN', $floor),
            $floor,
            (int) $router['id'],
            getEquipmentPort($routerName, 1),
            41
        );
        connectPanelPort(
            getPanelPort($panels[$floor], 41),
            $routerSocket,
            getEquipmentPort(sprintf('HTL-FW-L%d', $floor), 1)
        );
        $summary['sockets_created_or_updated']++;
        $summary['infrastructure_routes_connected']++;

        $firewallName = sprintf('HTL-FW-L%d', $floor);
        $firewall = getEquipment($firewallName);
        $firewallSocket = ensureSocket(
            sprintf('HTL-L%d-FIREWALL-LAN', $floor),
            $floor,
            (int) $firewall['id'],
            getEquipmentPort($firewallName, 2),
            42
        );
        connectPanelPort(
            getPanelPort($panels[$floor], 42),
            $firewallSocket,
            getEquipmentPort(sprintf('HTL-SW-L%d-01', $floor), 49)
        );
        $summary['sockets_created_or_updated']++;
        $summary['infrastructure_routes_connected']++;
    }

    for ($floor = 2; $floor <= 5; $floor++) {
        $localFloorOnePort = 43 + $floor;
        connectPanelPort(
            getPanelPort($panels[1], $localFloorOnePort),
            0,
            getEquipmentPort('HTL-SW-L1-02', 47 + $floor)
        );
        connectPanelPort(
            getPanelPort($panels[$floor], 48),
            0,
            getEquipmentPort(sprintf('HTL-SW-L%d-02', $floor), 49)
        );
        if (!PluginPatchpanelPanelPortLink::saveForPorts(
            getPanelPort($panels[1], $localFloorOnePort),
            getPanelPort($panels[$floor], 48),
            [
                'panel_link_media_type' => 'fiber-sm',
                'panel_link_cable_label' => sprintf('HTL-BACKBONE-L1-L%d', $floor),
                'panel_link_comment' => 'Hotel floor backbone',
            ]
        )) {
            throw new RuntimeException("Could not create backbone link from floor 1 to floor {$floor}.");
        }
        $summary['panel_links_created']++;
    }

    $DB->commit();
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    $DB->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
