<?php

declare(strict_types=1);

$glpiRoot = getenv('GLPI_ROOT') ?: '/var/www/glpi';
require $glpiRoot . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;

$ports = iterator_to_array($DB->request([
    'SELECT' => ['id', 'plugin_patchpanel_panels_id'],
    'FROM' => PluginPatchpanelPanelPort::getTable(),
    'ORDER' => ['id ASC'],
]));

$first = null;
$second = null;
foreach ($ports as $candidate) {
    $candidateId = (int) $candidate['id'];
    if (
        PluginPatchpanelPanelPortLink::hasActiveLink($candidateId)
        || PluginPatchpanelPortEndpoint::getForPort($candidateId)
    ) {
        continue;
    }
    if ($first === null) {
        $first = $candidate;
        continue;
    }
    if (
        (int) $candidate['plugin_patchpanel_panels_id']
        !== (int) $first['plugin_patchpanel_panels_id']
    ) {
        $second = $candidate;
        break;
    }
}

if ($first === null || $second === null) {
    fwrite(STDERR, "No two free ports on different panels are available for the route fixture.\n");
    exit(2);
}

$_SESSION['glpiID'] = 2;
$_SESSION['glpiactiveprofile'] = ['networking' => ALLSTANDARDRIGHT];
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactive_entity_recursive'] = 1;
$_SESSION['glpishowallentities'] = 1;

$frontPortIds = [];
foreach ($DB->request([
    'SELECT' => [NetworkPort::getTable() . '.id'],
    'FROM' => NetworkPort::getTable(),
    'INNER JOIN' => [
        NetworkEquipment::getTable() => [
            'FKEY' => [
                NetworkPort::getTable() => 'items_id',
                NetworkEquipment::getTable() => 'id',
            ],
        ],
    ],
    'WHERE' => [
        NetworkPort::getTable() . '.itemtype' => NetworkEquipment::class,
        NetworkPort::getTable() . '.is_deleted' => 0,
        NetworkEquipment::getTable() . '.is_deleted' => 0,
        'NOT' => [
            NetworkPort::getTable() . '.id' => new \Glpi\DBAL\QuerySubQuery([
                'SELECT' => 'items_id',
                'FROM' => PluginPatchpanelPortEndpoint::getTable(),
                'WHERE' => [
                    'side' => PluginPatchpanelPortEndpoint::FRONT,
                    'itemtype' => NetworkPort::class,
                ],
            ]),
        ],
    ],
    'ORDER' => [NetworkPort::getTable() . '.id ASC'],
]) as $frontPort) {
    $frontPortIds[] = (int) $frontPort['id'];
    if (count($frontPortIds) === 2) {
        break;
    }
}
if (count($frontPortIds) !== 2) {
    fwrite(STDERR, "No two free GLPI network ports are available for the route fixture.\n");
    exit(2);
}

[$portIdA, $portIdB] = PluginPatchpanelPanelPortLink::normalizePair(
    (int) $first['id'],
    (int) $second['id']
);

$DB->beginTransaction();
try {
    $DB->insert(PluginPatchpanelPanelPortLink::getTable(), [
        'panelports_id_a' => $portIdA,
        'panelports_id_b' => $portIdB,
        'cable_label' => 'ROUTE-CHECKPOINT',
        'media_type' => 'fiber',
        'is_active' => 1,
        'date_creation' => date('Y-m-d H:i:s'),
        'date_mod' => date('Y-m-d H:i:s'),
    ]);
    $rearOnlyStatusMap = PluginPatchpanelPanelPort::getDisplayStatusMapForRows([
        ['id' => $portIdA, 'operational_state' => 'active'],
        ['id' => $portIdB, 'operational_state' => 'active'],
    ]);
    foreach ([
        $portIdA => $frontPortIds[0],
        $portIdB => $frontPortIds[1],
    ] as $panelPortId => $frontPortId) {
        $DB->insert(PluginPatchpanelPortEndpoint::getTable(), [
            'plugin_patchpanel_panelports_id' => $panelPortId,
            'side' => PluginPatchpanelPortEndpoint::FRONT,
            'itemtype' => NetworkPort::class,
            'items_id' => $frontPortId,
            'cables_id' => 0,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod' => date('Y-m-d H:i:s'),
        ]);
    }

    $stepsA = PluginPatchpanelRoute::getStepsForPort($portIdA);
    $stepsB = PluginPatchpanelRoute::getStepsForPort($portIdB);
    $summarize = static fn(array $steps): array => array_map(
        static fn(array $step): array => [
            'type' => $step['type'] ?? '',
            'id' => (int) ($step['id'] ?? 0),
            'label' => $step['label'] ?? '',
            'network_port_ids' => array_values(array_map(
                static fn(array $reference): int => (int) ($reference['id'] ?? 0),
                array_filter(
                    $step['references'] ?? [],
                    static fn(array $reference): bool =>
                        ($reference['type'] ?? '') === NetworkPort::class
                )
            )),
        ],
        $steps
    );
    $summaryA = $summarize($stepsA);
    $summaryB = $summarize($stepsB);
    $panelPortA = new PluginPatchpanelPanelPort();
    $panelPortA->getFromDB($portIdA);
    $statusMap = PluginPatchpanelPanelPort::getDisplayStatusMapForRows([
        ['id' => $portIdA, 'operational_state' => 'active'],
        ['id' => $portIdB, 'operational_state' => 'active'],
    ]);

    $hasHop = static function (array $summary, int $peerId): bool {
        $types = array_column($summary, 'type');
        foreach ($summary as $step) {
            if (
                $step['type'] === PluginPatchpanelPanelPort::class
                && $step['id'] === $peerId
            ) {
                return in_array(PluginPatchpanelPanelPortLink::class, $types, true);
            }
        }
        return false;
    };

    $result = [
        'port_a' => $portIdA,
        'port_b' => $portIdB,
        'a_has_symmetric_hop' => $hasHop($summaryA, $portIdB),
        'b_has_symmetric_hop' => $hasHop($summaryB, $portIdA),
        'a_uses_local_then_peer_front' => selfTestFrontOrder(
            $summaryA,
            $frontPortIds[0],
            $frontPortIds[1]
        ),
        'b_uses_local_then_peer_front' => selfTestFrontOrder(
            $summaryB,
            $frontPortIds[1],
            $frontPortIds[0]
        ),
        'a_status' => $panelPortA->getID() > 0
            ? $panelPortA->getDisplayStatus()
            : 'missing',
        'grid_statuses' => $statusMap,
        'rear_link_only_statuses' => $rearOnlyStatusMap,
        'a_steps' => $summaryA,
        'b_steps' => $summaryB,
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    if (
        !$result['a_has_symmetric_hop']
        || !$result['b_has_symmetric_hop']
        || !$result['a_uses_local_then_peer_front']
        || !$result['b_uses_local_then_peer_front']
        || $result['a_status'] !== 'connected'
        || ($statusMap[$portIdA] ?? '') !== 'connected'
        || ($statusMap[$portIdB] ?? '') !== 'connected'
        || ($rearOnlyStatusMap[$portIdA] ?? '') !== 'partial'
        || ($rearOnlyStatusMap[$portIdB] ?? '') !== 'partial'
    ) {
        exit(1);
    }
} finally {
    $DB->rollBack();
}

function selfTestFrontOrder(array $steps, int $localFrontId, int $peerFrontId): bool
{
    $positions = [];
    foreach ($steps as $index => $step) {
        if (($step['type'] ?? '') === NetworkPort::class) {
            $positions[(int) $step['id']] = $index;
        }
        foreach ($step['network_port_ids'] ?? [] as $networkPortId) {
            $positions[(int) $networkPortId] = $index;
        }
    }
    return isset($positions[$localFrontId], $positions[$peerFrontId])
        && $positions[$localFrontId] < $positions[$peerFrontId];
}
