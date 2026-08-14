<?php

declare(strict_types=1);

$glpiRoot = getenv('GLPI_ROOT') ?: '/var/www/glpi';
require $glpiRoot . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;

PluginPatchpanelMigration::installSchema();

$managedTable = 'glpi_plugin_patchpanel_impactrelations';
$impactTable = ImpactRelation::getTable();
if (!$DB->tableExists($managedTable)) {
    throw new RuntimeException('Managed impact relation table was not installed.');
}
if (
    version_compare(GLPI_VERSION, '11.0.0', '>=')
    && !$DB->fieldExists($impactTable, 'name')
) {
    throw new RuntimeException('GLPI 11 runtime requires the official impact relation name field.');
}

$sessionBefore = $_SESSION;
$DB->beginTransaction();
try {
    if (!PluginPatchpanelImpact::synchronize()) {
        throw new RuntimeException('Initial impact synchronization failed.');
    }
    if ($_SESSION !== $sessionBefore) {
        throw new RuntimeException('Impact synchronization did not restore the caller session.');
    }

    $managed = iterator_to_array($DB->request(['FROM' => $managedTable]));
    if (!$managed) {
        throw new RuntimeException('Impact synchronization created no managed relations.');
    }
    foreach ($managed as $row) {
        if (countElementsInTable($impactTable, [
            'id' => (int) $row['impactrelations_id'],
            'itemtype_source' => $row['itemtype_source'],
            'items_id_source' => (int) $row['items_id_source'],
            'itemtype_impacted' => $row['itemtype_impacted'],
            'items_id_impacted' => (int) $row['items_id_impacted'],
        ]) !== 1) {
            throw new RuntimeException('Managed ownership row does not match its native relation.');
        }
    }

    $baselineManagedCount = count($managed);
    $DB->insert('glpi_entities', [
        'name' => 'PATCHPANEL-IMPACT-TEST-ENTITY',
        'entities_id' => 0,
        'completename' => 'Root entity > PATCHPANEL-IMPACT-TEST-ENTITY',
        'level' => 2,
    ]);
    $otherEntityId = (int) $DB->insertId();
    $DB->update(PluginPatchpanelPanel::getTable(), [
        'entities_id' => $otherEntityId,
    ], ['is_deleted' => 0]);
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactive_entity'] = 0;
    $restrictedSession = $_SESSION;
    if (!PluginPatchpanelImpact::synchronize()) {
        throw new RuntimeException('Multi-entity synchronization failed.');
    }
    if (
        countElementsInTable($managedTable) !== $baselineManagedCount
        || $_SESSION !== $restrictedSession
    ) {
        throw new RuntimeException('Caller entity scope leaked into the global impact graph.');
    }

    $first = reset($managed);
    $manualId = (int) $first['impactrelations_id'];
    $DB->delete($managedTable, ['id' => (int) $first['id']]);
    if (!PluginPatchpanelImpact::synchronize()) {
        throw new RuntimeException('Synchronization with a matching manual relation failed.');
    }
    if (
        countElementsInTable($impactTable, ['id' => $manualId]) !== 1
        || countElementsInTable($managedTable, ['impactrelations_id' => $manualId]) !== 0
    ) {
        throw new RuntimeException('Matching manual relation was overwritten or adopted.');
    }

    $owned = $DB->request(['FROM' => $managedTable, 'LIMIT' => 1])->current();
    if (!$owned) {
        throw new RuntimeException('No owned relation available for stale-row recovery test.');
    }
    $staleNativeId = (int) $owned['impactrelations_id'];
    $DB->delete($impactTable, ['id' => $staleNativeId]);
    if (!PluginPatchpanelImpact::synchronize()) {
        throw new RuntimeException('Stale ownership recovery failed.');
    }
    if (
        countElementsInTable($managedTable, ['impactrelations_id' => $staleNativeId]) !== 0
        || countElementsInTable($impactTable, [
            'itemtype_source' => $owned['itemtype_source'],
            'items_id_source' => (int) $owned['items_id_source'],
            'itemtype_impacted' => $owned['itemtype_impacted'],
            'items_id_impacted' => (int) $owned['items_id_impacted'],
        ]) !== 1
    ) {
        throw new RuntimeException('Stale owned relation was not safely recreated.');
    }

    $managedCount = countElementsInTable($managedTable);
    $nativeCount = countElementsInTable($impactTable);
    if (!PluginPatchpanelImpact::synchronize()) {
        throw new RuntimeException('Idempotent synchronization failed.');
    }
    if (
        countElementsInTable($managedTable) !== $managedCount
        || countElementsInTable($impactTable) !== $nativeCount
    ) {
        throw new RuntimeException('Repeated synchronization was not idempotent.');
    }

    if (!PluginPatchpanelImpact::removeManagedRelations()) {
        throw new RuntimeException('Managed impact cleanup failed.');
    }
    if (countElementsInTable($managedTable) !== 0) {
        throw new RuntimeException('Managed ownership rows remained after cleanup.');
    }
    if (countElementsInTable($impactTable, ['id' => $manualId]) !== 1) {
        throw new RuntimeException('Managed cleanup deleted a manual native relation.');
    }

    echo json_encode([
        'version_adaptive_schema' => true,
        'managed_relations' => $managedCount,
        'manual_relation_preserved' => true,
        'stale_relation_recovered' => true,
        'idempotent' => true,
        'session_restored' => true,
        'multi_entity_scope_independent' => true,
        'cleanup_safe' => true,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $DB->rollBack();
}
