<?php

/** Synchronize physical routes with GLPI's native impact graph. */
final class PluginPatchpanelImpact
{
    private const MANAGED_TABLE = 'glpi_plugin_patchpanel_impactrelations';
    private const RELATION_LABEL = 'PatchPanel automatic physical route';

    private static bool $synchronizing = false;

    /**
     * Reconcile only relations owned by PatchPanel. Native/manual relations
     * are never adopted merely because they describe the same edge.
     */
    public static function synchronize(): bool
    {
        global $DB;

        if (self::$synchronizing || !class_exists(ImpactRelation::class)) {
            return true;
        }
        $impactTable = ImpactRelation::getTable();
        if (!$DB->tableExists($impactTable) || !$DB->tableExists(self::MANAGED_TABLE)) {
            return true;
        }
        if (!self::acquireDatabaseLock()) {
            Toolbox::logInFile('php-errors', "PatchPanel impact synchronization lock timed out.\n");
            return false;
        }

        $sessionSnapshot = $_SESSION;
        self::$synchronizing = true;
        PluginPatchpanelRoute::clearCaches();
        try {
            self::useAllEntityRouteContext();
            $desired = self::buildDesiredRelations();

            $DB->beginTransaction();
            try {
                self::removeObsoleteRelations($desired, $impactTable);
                self::createMissingRelations($desired, $impactTable);
                $DB->commit();
            } catch (Throwable $e) {
                $DB->rollBack();
                throw $e;
            }
            return true;
        } catch (Throwable $e) {
            Toolbox::logInFile(
                'php-errors',
                'PatchPanel impact synchronization failed: ' . $e->getMessage() . "\n"
            );
            return false;
        } finally {
            $_SESSION = $sessionSnapshot;
            PluginPatchpanelRoute::clearCaches();
            self::$synchronizing = false;
            self::releaseDatabaseLock();
        }
    }

    private static function acquireDatabaseLock(): bool
    {
        global $DB;

        $result = $DB->doQuery("SELECT GET_LOCK('glpi_patchpanel_impact_sync', 5) AS acquired");
        $row = $result ? $result->fetch_assoc() : null;
        return (int) ($row['acquired'] ?? 0) === 1;
    }

    private static function releaseDatabaseLock(): void
    {
        global $DB;

        $DB->doQuery("SELECT RELEASE_LOCK('glpi_patchpanel_impact_sync')");
    }

    /** Delete owned native relations before plugin data is uninstalled. */
    public static function removeManagedRelations(): bool
    {
        global $DB;

        if (!class_exists(ImpactRelation::class) || !$DB->tableExists(self::MANAGED_TABLE)) {
            return true;
        }
        $impactTable = ImpactRelation::getTable();
        if (!$DB->tableExists($impactTable)) {
            return true;
        }
        if (!self::acquireDatabaseLock()) {
            Toolbox::logInFile('php-errors', "PatchPanel impact cleanup lock timed out.\n");
            return false;
        }

        $DB->beginTransaction();
        try {
            foreach ($DB->request(['FROM' => self::MANAGED_TABLE]) as $managed) {
                self::deleteOwnedNativeRelation($managed, $impactTable);
            }
            $DB->delete(self::MANAGED_TABLE, ['id' => ['>', 0]]);
            $DB->commit();
            return true;
        } catch (Throwable $e) {
            $DB->rollBack();
            Toolbox::logInFile(
                'php-errors',
                'PatchPanel impact cleanup failed: ' . $e->getMessage() . "\n"
            );
            return false;
        } finally {
            self::releaseDatabaseLock();
        }
    }

    private static function useAllEntityRouteContext(): void
    {
        global $DB;

        $entityIds = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities']) as $entity) {
            $entityIds[] = (int) $entity['id'];
        }
        $_SESSION['glpiID'] = 0;
        $_SESSION['glpiactiveprofile'] = ['networking' => ALLSTANDARDRIGHT];
        $_SESSION['glpiactiveentities'] = $entityIds ?: [0];
        $_SESSION['glpiactiveentities_string'] = "'" . implode("', '", $entityIds ?: [0]) . "'";
        $_SESSION['glpiactive_entity'] = 0;
        $_SESSION['glpiactive_entity_recursive'] = 1;
        $_SESSION['glpishowallentities'] = 1;
    }

    private static function buildDesiredRelations(): array
    {
        global $DB;

        $relations = [];
        foreach ($DB->request([
            'SELECT' => [PluginPatchpanelPanelPort::getTable() . '.id'],
            'FROM' => PluginPatchpanelPanelPort::getTable(),
            'INNER JOIN' => [
                PluginPatchpanelPanel::getTable() => [
                    'FKEY' => [
                        PluginPatchpanelPanelPort::getTable() => 'plugin_patchpanel_panels_id',
                        PluginPatchpanelPanel::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE' => [PluginPatchpanelPanel::getTable() . '.is_deleted' => 0],
        ]) as $port) {
            $devices = self::getRouteDevices((int) $port['id']);
            for ($index = count($devices) - 1; $index > 0; $index--) {
                $edge = self::deviceEdge($devices[$index], $devices[$index - 1]);
                $relations[self::edgeKey($edge)] = $edge;
            }
        }
        return $relations;
    }

    private static function removeObsoleteRelations(array $desired, string $impactTable): void
    {
        global $DB;

        foreach ($DB->request(['FROM' => self::MANAGED_TABLE]) as $managed) {
            if (isset($desired[self::edgeKey($managed)])) {
                continue;
            }
            self::deleteOwnedNativeRelation($managed, $impactTable);
            if (!$DB->delete(self::MANAGED_TABLE, ['id' => (int) $managed['id']])) {
                throw new RuntimeException('Could not delete obsolete impact ownership row.');
            }
        }
    }

    private static function createMissingRelations(array $desired, string $impactTable): void
    {
        global $DB;

        foreach ($desired as $edge) {
            $managed = self::findEdge(self::MANAGED_TABLE, $edge);
            if ($managed && self::nativeRelationMatches($managed, $impactTable)) {
                self::ensureNativeLabel((int) $managed['impactrelations_id'], $impactTable);
                continue;
            }
            if ($managed) {
                $DB->delete(self::MANAGED_TABLE, ['id' => (int) $managed['id']]);
            }

            // An existing untracked relation belongs to GLPI/the user. It
            // already satisfies the graph and must remain outside our lifecycle.
            if (self::findEdge($impactTable, $edge)) {
                continue;
            }
            $nativeEdge = $edge;
            if ($DB->fieldExists($impactTable, 'name')) {
                $nativeEdge['name'] = self::RELATION_LABEL;
            }
            if (!$DB->insert($impactTable, $nativeEdge)) {
                throw new RuntimeException('Could not create native impact relation.');
            }
            $managedEdge = $edge + [
                'impactrelations_id' => (int) $DB->insertId(),
                'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ];
            if (!$DB->insert(self::MANAGED_TABLE, $managedEdge)) {
                throw new RuntimeException('Could not record impact relation ownership.');
            }
        }
    }

    private static function ensureNativeLabel(int $relationId, string $impactTable): void
    {
        global $DB;

        if (!$DB->fieldExists($impactTable, 'name')) {
            return;
        }
        if (!$DB->update($impactTable, ['name' => self::RELATION_LABEL], [
            'id' => $relationId,
            'NOT' => ['name' => self::RELATION_LABEL],
        ])) {
            throw new RuntimeException('Could not update native impact relation label.');
        }
    }

    private static function deleteOwnedNativeRelation(array $managed, string $impactTable): void
    {
        global $DB;

        if (!self::nativeRelationMatches($managed, $impactTable)) {
            return;
        }
        if (!$DB->delete($impactTable, ['id' => (int) $managed['impactrelations_id']])) {
            throw new RuntimeException('Could not delete owned native impact relation.');
        }
    }

    private static function nativeRelationMatches(array $managed, string $impactTable): bool
    {
        global $DB;

        return (bool) $DB->request([
            'SELECT' => ['id'],
            'FROM' => $impactTable,
            'WHERE' => ['id' => (int) $managed['impactrelations_id']] + self::edge($managed),
            'LIMIT' => 1,
        ])->current();
    }

    private static function findEdge(string $table, array $edge): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM' => $table,
            'WHERE' => self::edge($edge),
            'LIMIT' => 1,
        ])->current();
        return $row ?: null;
    }

    private static function edge(array $source): array
    {
        return [
            'itemtype_source' => (string) $source['itemtype_source'],
            'items_id_source' => (int) $source['items_id_source'],
            'itemtype_impacted' => (string) $source['itemtype_impacted'],
            'items_id_impacted' => (int) $source['items_id_impacted'],
        ];
    }

    private static function deviceEdge(array $source, array $impacted): array
    {
        return [
            'itemtype_source' => $source['type'],
            'items_id_source' => $source['id'],
            'itemtype_impacted' => $impacted['type'],
            'items_id_impacted' => $impacted['id'],
        ];
    }

    private static function edgeKey(array $edge): string
    {
        $edge = self::edge($edge);
        return implode(':', $edge);
    }

    /** Return unique GLPI impact-enabled assets in endpoint-to-upstream order. */
    private static function getRouteDevices(int $portId): array
    {
        $devices = [];
        foreach (PluginPatchpanelRoute::getStepsForPort($portId) as $step) {
            self::addDevice($devices, $step);
            foreach (($step['references'] ?? []) as $reference) {
                self::addDevice($devices, $reference);
            }
        }
        return array_values($devices);
    }

    private static function addDevice(array &$devices, array $step): void
    {
        $type = (string) ($step['type'] ?? '');
        if ($type === '' || !Impact::isEnabled($type) || !empty($step['broken'])) {
            return;
        }
        $id = (int) ($step['id'] ?? 0);
        $key = $type . ':' . $id;
        if ($id > 0 && !isset($devices[$key])) {
            $devices[$key] = ['type' => $type, 'id' => $id];
        }
    }
}
