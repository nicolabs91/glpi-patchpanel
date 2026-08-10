<?php

declare(strict_types=1);

$glpiRoot = getenv('GLPI_ROOT') ?: '/var/www/glpi';
require $glpiRoot . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;

$endpointTable = PluginPatchpanelPortEndpoint::getTable();
$linkTable = PluginPatchpanelPanelPortLink::getTable();
$candidateIds = [];
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
        $candidateIds[] = $portId;
    }
    if (count($candidateIds) === 3) {
        break;
    }
}
if (count($candidateIds) !== 3) {
    throw new RuntimeException('Three free panel ports are required for the migration checkpoint.');
}

[$portIdA, $portIdB, $portIdC] = $candidateIds;
$now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
[$conflictA, $conflictB] = PluginPatchpanelPanelPortLink::normalizePair($portIdA, $portIdC);
try {
    if (!$DB->insert($linkTable, [
        'panelports_id_a' => $conflictA,
        'panelports_id_b' => $conflictB,
        'media_type' => 'other',
        'is_active' => 1,
        'date_creation' => $now,
        'date_mod' => $now,
    ])) {
        throw new RuntimeException('Could not create the canonical conflict fixture.');
    }
    foreach ([[$portIdA, $portIdB], [$portIdB, $portIdA]] as [$sourceId, $targetId]) {
        if (!$DB->insert($endpointTable, [
            'plugin_patchpanel_panelports_id' => $sourceId,
            'side' => PluginPatchpanelPortEndpoint::REAR,
            'itemtype' => PluginPatchpanelPanelPort::class,
            'items_id' => $targetId,
            'cables_id' => 0,
            'date_creation' => $now,
            'date_mod' => $now,
        ])) {
            throw new RuntimeException('Could not create reciprocal experimental endpoints.');
        }
    }

    $method = new ReflectionMethod(PluginPatchpanelMigration::class, 'migrateExperimentalPanelPortLinks');
    $method->setAccessible(true);
    $method->invoke(null);

    [$normalizedA, $normalizedB] = PluginPatchpanelPanelPortLink::normalizePair($portIdA, $portIdB);
    $unexpectedLink = countElementsInTable($linkTable, [
        'panelports_id_a' => $normalizedA,
        'panelports_id_b' => $normalizedB,
        'is_active' => 1,
    ]);
    $preservedEndpoints = countElementsInTable($endpointTable, [
        'plugin_patchpanel_panelports_id' => [$portIdA, $portIdB],
        'side' => PluginPatchpanelPortEndpoint::REAR,
        'itemtype' => PluginPatchpanelPanelPort::class,
    ]);
    if ($unexpectedLink !== 0 || $preservedEndpoints !== 2) {
        throw new RuntimeException(
            'Migration overwrote an occupied rear side or removed its recoverable source rows.'
        );
    }

    echo "Panel-link migration conflict checkpoint passed for ports {$portIdA}, {$portIdB}, and {$portIdC}.\n";
} finally {
    $DB->delete($endpointTable, [
        'plugin_patchpanel_panelports_id' => [$portIdA, $portIdB],
        'side' => PluginPatchpanelPortEndpoint::REAR,
        'itemtype' => PluginPatchpanelPanelPort::class,
    ]);
    $DB->delete($linkTable, [
        'OR' => [
            'panelports_id_a' => [$portIdA, $portIdB, $portIdC],
            'panelports_id_b' => [$portIdA, $portIdB, $portIdC],
        ],
    ]);
}
