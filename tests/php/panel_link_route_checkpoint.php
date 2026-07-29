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
        || PluginPatchpanelPortEndpoint::getForPort($candidateId)[PluginPatchpanelPortEndpoint::REAR] ?? false
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

    $stepsA = PluginPatchpanelRoute::getStepsForPort($portIdA);
    $stepsB = PluginPatchpanelRoute::getStepsForPort($portIdB);
    $summarize = static fn(array $steps): array => array_map(
        static fn(array $step): array => [
            'type' => $step['type'] ?? '',
            'id' => (int) ($step['id'] ?? 0),
            'label' => $step['label'] ?? '',
        ],
        $steps
    );
    $summaryA = $summarize($stepsA);
    $summaryB = $summarize($stepsB);

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
        'a_steps' => $summaryA,
        'b_steps' => $summaryB,
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    if (!$result['a_has_symmetric_hop'] || !$result['b_has_symmetric_hop']) {
        exit(1);
    }
} finally {
    $DB->rollBack();
}
