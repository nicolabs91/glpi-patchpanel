<?php

final class PluginPatchpanelMigration
{
    public const LEGACY_PANELS = 'glpi_plugin_patchpanel_patchpanels';
    public const LEGACY_PORTS = 'glpi_plugin_patchpanel_items_patchpanels';

    public static function installSchema(): void
    {
        global $DB;

        $queries = [
            'glpi_plugin_patchpanel_panelmodels' => <<<'SQL'
CREATE TABLE `glpi_plugin_patchpanel_panelmodels` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `port_count` smallint unsigned NOT NULL DEFAULT 24,
  `rows` tinyint unsigned NOT NULL DEFAULT 1,
  `media` varchar(32) NOT NULL DEFAULT 'copper',
  `comment` text DEFAULT NULL,
  `date_mod` timestamp NULL DEFAULT NULL,
  `date_creation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'glpi_plugin_patchpanel_panels' => <<<'SQL'
CREATE TABLE `glpi_plugin_patchpanel_panels` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `entities_id` int unsigned NOT NULL DEFAULT 0,
  `is_recursive` tinyint NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `serial` varchar(255) DEFAULT NULL,
  `otherserial` varchar(255) DEFAULT NULL,
  `locations_id` int unsigned NOT NULL DEFAULT 0,
  `plugin_patchpanel_panelmodels_id` int unsigned NOT NULL DEFAULT 0,
  `port_count` smallint unsigned NOT NULL DEFAULT 24,
  `rows` tinyint unsigned NOT NULL DEFAULT 1,
  `media` varchar(32) NOT NULL DEFAULT 'copper',
  `comment` text DEFAULT NULL,
  `is_deleted` tinyint NOT NULL DEFAULT 0,
  `date_mod` timestamp NULL DEFAULT NULL,
  `date_creation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `entity_deleted_name` (`entities_id`,`is_deleted`,`name`),
  KEY `locations_id` (`locations_id`),
  KEY `model_id` (`plugin_patchpanel_panelmodels_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'glpi_plugin_patchpanel_panelports' => <<<'SQL'
CREATE TABLE `glpi_plugin_patchpanel_panelports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plugin_patchpanel_panels_id` int unsigned NOT NULL,
  `number` smallint unsigned NOT NULL,
  `row` tinyint unsigned NOT NULL DEFAULT 1,
  `position` smallint unsigned NOT NULL DEFAULT 1,
  `label` varchar(255) DEFAULT NULL,
  `operational_state` varchar(24) NOT NULL DEFAULT 'active',
  `media` varchar(32) NOT NULL DEFAULT 'copper',
  `comment` text DEFAULT NULL,
  `date_mod` timestamp NULL DEFAULT NULL,
  `date_creation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `panel_number` (`plugin_patchpanel_panels_id`,`number`),
  KEY `panel_layout` (`plugin_patchpanel_panels_id`,`row`,`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'glpi_plugin_patchpanel_portendpoints' => <<<'SQL'
CREATE TABLE `glpi_plugin_patchpanel_portendpoints` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plugin_patchpanel_panelports_id` int unsigned NOT NULL,
  `side` varchar(5) NOT NULL,
  `itemtype` varchar(255) NOT NULL,
  `items_id` int unsigned NOT NULL,
  `cables_id` int unsigned NOT NULL DEFAULT 0,
  `cable_color` varchar(24) DEFAULT NULL,
  `cable_label` varchar(255) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `date_mod` timestamp NULL DEFAULT NULL,
  `date_creation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `port_side` (`plugin_patchpanel_panelports_id`,`side`),
  UNIQUE KEY `endpoint` (`itemtype`,`items_id`),
  KEY `cables_id` (`cables_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'glpi_plugin_patchpanel_migrations' => <<<'SQL'
CREATE TABLE `glpi_plugin_patchpanel_migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_table` varchar(255) NOT NULL,
  `source_id` int unsigned NOT NULL,
  `target_itemtype` varchar(255) DEFAULT NULL,
  `target_items_id` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `message` text DEFAULT NULL,
  `date_creation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source` (`source_table`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'glpi_plugin_patchpanel_importbatches' => <<<'SQL'
CREATE TABLE `glpi_plugin_patchpanel_importbatches` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `batch_uuid` varchar(64) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'applied',
  `row_count` int unsigned NOT NULL DEFAULT 0,
  `users_id` int unsigned NOT NULL DEFAULT 0,
  `date_creation` timestamp NULL DEFAULT NULL,
  `date_mod` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_uuid` (`batch_uuid`),
  KEY `status_date` (`status`,`date_creation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'glpi_plugin_patchpanel_importchanges' => <<<'SQL'
CREATE TABLE `glpi_plugin_patchpanel_importchanges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `batch_uuid` varchar(64) NOT NULL,
  `plugin_patchpanel_panelports_id` int unsigned NOT NULL,
  `before_json` longtext NOT NULL,
  `after_json` longtext NOT NULL,
  `date_creation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_port` (`batch_uuid`,`plugin_patchpanel_panelports_id`),
  KEY `port_id` (`plugin_patchpanel_panelports_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];

        foreach ($queries as $table => $query) {
            if (!$DB->tableExists($table)) {
                $DB->doQuery($query);
            }
        }

        self::upgradeMigrationTable();
        self::seedModels();
    }

    private static function upgradeMigrationTable(): void
    {
        global $DB;

        $table = 'glpi_plugin_patchpanel_migrations';
        if (!$DB->fieldExists($table, 'batch_uuid')) {
            $DB->doQuery(
                "ALTER TABLE `$table`
                 ADD `batch_uuid` varchar(64) DEFAULT NULL AFTER `id`,
                 ADD KEY `batch_uuid` (`batch_uuid`)"
            );
        }
        if (!$DB->fieldExists($table, 'date_mod')) {
            $DB->doQuery(
                "ALTER TABLE `$table`
                 ADD `date_mod` timestamp NULL DEFAULT NULL AFTER `date_creation`"
            );
        }
    }

    private static function seedModels(): void
    {
        global $DB;

        $table = 'glpi_plugin_patchpanel_panelmodels';
        $oldFiber = $DB->request([
            'SELECT' => ['id'],
            'FROM' => $table,
            'WHERE' => ['name' => '24-port fiber, 1U'],
            'LIMIT' => 1,
        ])->current();
        $newFiber = $DB->request([
            'SELECT' => ['id'],
            'FROM' => $table,
            'WHERE' => ['name' => '24-port multimode fiber, 1U'],
            'LIMIT' => 1,
        ])->current();
        if ($oldFiber && $newFiber) {
            if ($DB->tableExists('glpi_plugin_patchpanel_panels')) {
                $DB->update('glpi_plugin_patchpanel_panels', [
                    'plugin_patchpanel_panelmodels_id' => (int) $newFiber['id'],
                ], [
                    'plugin_patchpanel_panelmodels_id' => (int) $oldFiber['id'],
                ]);
            }
            $DB->delete($table, ['id' => (int) $oldFiber['id']]);
        } elseif ($oldFiber) {
            $DB->update($table, [
                'name' => '24-port multimode fiber, 1U',
                'media' => 'fiber-mm',
            ], ['id' => (int) $oldFiber['id']]);
        }

        foreach ([
            ['name' => '24-port copper, 1U', 'port_count' => 24, 'rows' => 1, 'media' => 'copper'],
            ['name' => '48-port copper, 2U', 'port_count' => 48, 'rows' => 2, 'media' => 'copper'],
            ['name' => '24-port multimode fiber, 1U', 'port_count' => 24, 'rows' => 1, 'media' => 'fiber-mm'],
        ] as $model) {
            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM' => $table,
                'WHERE' => ['name' => $model['name']],
                'LIMIT' => 1,
            ])->current();
            if (!$existing) {
                $model['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
                $DB->insert($table, $model);
            } else {
                $DB->update($table, [
                    'port_count' => $model['port_count'],
                    'rows' => $model['rows'],
                    'media' => $model['media'],
                ], ['id' => $existing['id']]);
            }
        }

    }

    public static function getLegacySummary(): array
    {
        global $DB;

        return [
            'available' => $DB->tableExists(self::LEGACY_PANELS)
                && $DB->tableExists(self::LEGACY_PORTS),
            'panels' => $DB->tableExists(self::LEGACY_PANELS)
                ? countElementsInTable(self::LEGACY_PANELS)
                : 0,
            'ports' => $DB->tableExists(self::LEGACY_PORTS)
                ? countElementsInTable(self::LEGACY_PORTS)
                : 0,
        ];
    }

    public static function analyzeLegacy(): array
    {
        global $DB;

        $summary = self::getLegacySummary();
        $analysis = [
            'available' => $summary['available'],
            'summary' => [
                'panels' => $summary['panels'],
                'ports' => $summary['ports'],
                'ready' => 0,
                'empty' => 0,
                'partial' => 0,
                'conflict' => 0,
                'invalid' => 0,
                'imported_panels' => 0,
            ],
            'panels' => [],
        ];
        if (!$summary['available']) {
            return $analysis;
        }

        $frontCandidates = [];
        $rearCandidates = [];
        $panelRows = [];
        foreach ($DB->request([
            'FROM' => self::LEGACY_PANELS,
            'ORDER' => ['id ASC'],
        ]) as $legacyPanel) {
            $panelId = (int) $legacyPanel['id'];
            $ports = [];
            foreach ($DB->request([
                'FROM' => self::LEGACY_PORTS,
                'WHERE' => ['pluginpatchpanelpatchpanel_id' => $panelId],
                'ORDER' => ['logical_number ASC', 'id ASC'],
            ]) as $legacyPort) {
                $port = self::analyzeLegacyPort($legacyPort);
                $ports[] = $port;
                if ($port['rear']['candidate_id'] > 0) {
                    $key = $port['rear']['itemtype'] . ':' . $port['rear']['candidate_id'];
                    $rearCandidates[$key][] = (int) $legacyPort['id'];
                }
                if ($port['front']['candidate_id'] > 0) {
                    $key = $port['front']['itemtype'] . ':' . $port['front']['candidate_id'];
                    $frontCandidates[$key][] = (int) $legacyPort['id'];
                }
            }
            $panelRows[$panelId] = [
                'source' => $legacyPanel,
                'ports' => $ports,
            ];
        }

        $usedEndpoints = [];
        foreach ($DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM' => PluginPatchpanelPortEndpoint::getTable(),
        ]) as $endpoint) {
            $usedEndpoints[$endpoint['itemtype'] . ':' . (int) $endpoint['items_id']] = true;
        }

        foreach ($panelRows as $panelId => $panelData) {
            $counts = [
                'ready' => 0,
                'empty' => 0,
                'partial' => 0,
                'conflict' => 0,
                'invalid' => 0,
            ];
            $maxPort = 0;
            foreach ($panelData['ports'] as &$port) {
                $maxPort = max($maxPort, (int) $port['number']);
                foreach (['rear', 'front'] as $side) {
                    if ($port[$side]['candidate_id'] <= 0) {
                        continue;
                    }
                    $key = $port[$side]['itemtype'] . ':' . $port[$side]['candidate_id'];
                    $candidateSet = $side === 'rear' ? $rearCandidates : $frontCandidates;
                    if (count($candidateSet[$key] ?? []) > 1) {
                        $port[$side]['status'] = 'conflict';
                        $port['issues'][] = sprintf(
                            __('%s endpoint occurs on multiple legacy ports.', 'patchpanel'),
                            ucfirst($side)
                        );
                    }
                    if (isset($usedEndpoints[$key])) {
                        $port[$side]['status'] = 'conflict';
                        $port['issues'][] = sprintf(
                            __('%s endpoint is already used by the new plugin.', 'patchpanel'),
                            ucfirst($side)
                        );
                    }
                }
                $port['status'] = self::classifyAnalyzedPort($port);
                $counts[$port['status']]++;
                $analysis['summary'][$port['status']]++;
            }
            unset($port);

            $mapping = self::getMapping(self::LEGACY_PANELS, $panelId);
            $isImported = $mapping !== null
                && str_starts_with((string) $mapping['status'], 'imported')
                && (int) $mapping['target_items_id'] > 0;
            if ($isImported) {
                $analysis['summary']['imported_panels']++;
            }

            $analysis['panels'][] = [
                'id' => $panelId,
                'name' => (string) ($panelData['source']['name'] ?? ''),
                'entity_id' => (int) ($panelData['source']['entities_id'] ?? 0),
                'location_id' => (int) ($panelData['source']['locations_id'] ?? 0),
                'port_count' => max(24, $maxPort),
                'rows' => $maxPort > 24 ? 2 : 1,
                'counts' => $counts,
                'ports' => $panelData['ports'],
                'is_imported' => $isImported,
                'target_id' => $isImported ? (int) $mapping['target_items_id'] : 0,
                'source' => $panelData['source'],
            ];
        }

        return $analysis;
    }

    private static function analyzeLegacyPort(array $legacyPort): array
    {
        $rearId = (int) ($legacyPort['netpoints_id'] ?? 0);
        $oldItemtype = (string) ($legacyPort['itemtype'] ?? '');
        $oldItemsId = (int) ($legacyPort['items_id'] ?? 0);
        $port = [
            'id' => (int) $legacyPort['id'],
            'number' => (int) $legacyPort['logical_number'],
            'label' => (string) ($legacyPort['name'] ?? ''),
            'status' => 'invalid',
            'issues' => [],
            'rear' => self::emptyEndpoint('Glpi\\Socket'),
            'front' => self::emptyEndpoint(NetworkPort::class),
            'legacy_item' => self::emptyEndpoint($oldItemtype),
        ];

        if ($rearId > 0) {
            $port['rear'] = self::inspectEndpoint('Glpi\\Socket', $rearId);
            if ($port['rear']['status'] !== 'ready') {
                $port['issues'][] = __('Rear connection point is missing or inaccessible.', 'patchpanel');
            }
        }

        if ($oldItemsId <= 0 || $oldItemtype === '') {
            return $port;
        }

        $port['legacy_item'] = self::inspectEndpoint($oldItemtype, $oldItemsId);
        if ($oldItemtype !== NetworkPort::class || $port['legacy_item']['status'] !== 'ready') {
            $port['issues'][] = __('Legacy connected item is not a valid network port.', 'patchpanel');
            return $port;
        }

        $legacyNetworkPort = new NetworkPort();
        $legacyNetworkPort->getFromDB($oldItemsId);
        if (self::isInfrastructurePort($legacyNetworkPort)) {
            $port['front'] = self::inspectEndpoint(NetworkPort::class, $oldItemsId);
            return $port;
        }

        $peerId = (new NetworkPort_NetworkPort())->getOppositeContact($oldItemsId);
        if (!$peerId) {
            $port['issues'][] = __('No connected switch or router port could be derived.', 'patchpanel');
            return $port;
        }

        $peer = new NetworkPort();
        if (!$peer->getFromDB((int) $peerId) || !self::isInfrastructurePort($peer)) {
            $port['issues'][] = __('The connected peer is not a switch, router, or firewall port.', 'patchpanel');
            return $port;
        }
        $port['front'] = self::inspectEndpoint(NetworkPort::class, (int) $peerId);
        return $port;
    }

    private static function emptyEndpoint(string $itemtype): array
    {
        return [
            'itemtype' => $itemtype,
            'candidate_id' => 0,
            'label' => '',
            'status' => 'empty',
        ];
    }

    private static function inspectEndpoint(string $itemtype, int $id): array
    {
        $result = self::emptyEndpoint($itemtype);
        $result['candidate_id'] = $id;
        if (
            $id <= 0
            || !class_exists($itemtype)
            || !is_a($itemtype, CommonDBTM::class, true)
        ) {
            $result['status'] = 'invalid';
            return $result;
        }

        $item = new $itemtype();
        if (!$item->getFromDB($id) || !$item->canViewItem()) {
            $result['status'] = 'invalid';
            return $result;
        }

        $result['label'] = trim((string) $item->getName());
        if ($result['label'] === '') {
            $result['label'] = sprintf('%s #%d', $itemtype::getTypeName(1), $id);
        }
        $result['status'] = 'ready';
        return $result;
    }

    private static function isInfrastructurePort(NetworkPort $port): bool
    {
        global $DB;

        if (($port->fields['itemtype'] ?? '') !== NetworkEquipment::class) {
            return false;
        }
        $equipmentId = (int) ($port->fields['items_id'] ?? 0);
        $result = $DB->doQuery(
            "SELECT LOWER(CONCAT(ne.name, ' ', COALESCE(t.name, ''))) AS descriptor
             FROM glpi_networkequipments ne
             LEFT JOIN glpi_networkequipmenttypes t
               ON t.id = ne.networkequipmenttypes_id
             WHERE ne.id = " . $equipmentId
        );
        $row = $result ? $result->fetch_assoc() : null;
        return $row !== null
            && preg_match('/switch|router|firewall|gateway|core/', $row['descriptor']) === 1;
    }

    private static function classifyAnalyzedPort(array $port): string
    {
        $rear = $port['rear']['status'];
        $front = $port['front']['status'];
        if ($rear === 'empty' && $front === 'empty' && $port['legacy_item']['candidate_id'] === 0) {
            return 'empty';
        }
        if ($rear === 'conflict' || $front === 'conflict') {
            return 'conflict';
        }
        if ($rear === 'ready' && $front === 'ready') {
            return 'ready';
        }
        if ($rear === 'ready' || $front === 'ready') {
            return 'partial';
        }
        return 'invalid';
    }

    private static function getMapping(string $sourceTable, int $sourceId): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM' => 'glpi_plugin_patchpanel_migrations',
            'WHERE' => [
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
            ],
            'LIMIT' => 1,
        ])->current();
        return $row ?: null;
    }

    public static function importLegacyPanels(array $panelIds): array
    {
        global $DB;

        $panelIds = array_values(array_unique(array_filter(array_map('intval', $panelIds))));
        if (!$panelIds) {
            throw new InvalidArgumentException(__('Select at least one legacy panel.', 'patchpanel'));
        }

        $analysis = self::analyzeLegacy();
        $byId = [];
        foreach ($analysis['panels'] as $panel) {
            $byId[$panel['id']] = $panel;
        }

        $batch = bin2hex(random_bytes(16));
        $result = [
            'batch_uuid' => $batch,
            'panels' => 0,
            'ports' => 0,
            'partial_ports' => 0,
            'conflict_ports' => 0,
        ];

        $DB->beginTransaction();
        try {
            foreach ($panelIds as $panelId) {
                if (!isset($byId[$panelId])) {
                    throw new RuntimeException(sprintf(__('Legacy panel #%d no longer exists.', 'patchpanel'), $panelId));
                }
                $legacy = $byId[$panelId];
                if ($legacy['is_imported']) {
                    throw new RuntimeException(sprintf(__('Legacy panel #%d was already imported.', 'patchpanel'), $panelId));
                }
                if (!Session::haveAccessToEntity($legacy['entity_id'])) {
                    throw new RuntimeException(__('You do not have access to one of the selected panel entities.', 'patchpanel'));
                }

                $source = $legacy['source'];
                $panel = new PluginPatchpanelPanel();
                $targetPanelId = $panel->add([
                    'entities_id' => (int) ($source['entities_id'] ?? 0),
                    'is_recursive' => 0,
                    'name' => (string) ($source['name'] ?? ''),
                    'serial' => (string) ($source['serial'] ?? ''),
                    'otherserial' => (string) ($source['otherserial'] ?? ''),
                    'locations_id' => (int) ($source['locations_id'] ?? 0),
                    'port_count' => $legacy['port_count'],
                    'rows' => $legacy['rows'],
                    'media' => 'copper',
                    'comment' => trim(
                        (string) ($source['comment'] ?? '')
                        . "\n"
                        . sprintf(__('Imported from legacy PatchPanel panel #%d.', 'patchpanel'), $panelId)
                    ),
                ]);
                if (!$targetPanelId) {
                    throw new RuntimeException(sprintf(__('Could not create target panel for legacy panel #%d.', 'patchpanel'), $panelId));
                }

                foreach ($legacy['ports'] as $legacyPort) {
                    $targetPort = $DB->request([
                        'SELECT' => ['id'],
                        'FROM' => PluginPatchpanelPanelPort::getTable(),
                        'WHERE' => [
                            'plugin_patchpanel_panels_id' => $targetPanelId,
                            'number' => $legacyPort['number'],
                        ],
                        'LIMIT' => 1,
                    ])->current();
                    if (!$targetPort) {
                        throw new RuntimeException(__('A generated target port is missing.', 'patchpanel'));
                    }

                    $port = new PluginPatchpanelPanelPort();
                    if (!$port->update([
                        'id' => (int) $targetPort['id'],
                        'label' => $legacyPort['label'],
                    ])) {
                        throw new RuntimeException(__('Could not copy a legacy port label.', 'patchpanel'));
                    }

                    $rearId = $legacyPort['rear']['status'] === 'ready'
                        ? (int) $legacyPort['rear']['candidate_id']
                        : 0;
                    $frontId = $legacyPort['front']['status'] === 'ready'
                        ? (int) $legacyPort['front']['candidate_id']
                        : 0;
                    if ($rearId > 0 || $frontId > 0) {
                        if (!PluginPatchpanelPortEndpoint::saveForPort(
                            (int) $targetPort['id'],
                            [
                                'rear_items_id' => $rearId,
                                'front_items_id' => $frontId,
                            ],
                            false
                        )) {
                            throw new RuntimeException(__('Could not create an imported endpoint.', 'patchpanel'));
                        }
                    }

                    $mappingStatus = match ($legacyPort['status']) {
                        'ready' => 'imported',
                        'empty' => 'imported_empty',
                        default => 'imported_partial',
                    };
                    self::saveMapping(
                        $batch,
                        self::LEGACY_PORTS,
                        $legacyPort['id'],
                        PluginPatchpanelPanelPort::class,
                        (int) $targetPort['id'],
                        $mappingStatus,
                        implode(' ', $legacyPort['issues'])
                    );
                    $result['ports']++;
                    if ($legacyPort['status'] === 'partial' || $legacyPort['status'] === 'invalid') {
                        $result['partial_ports']++;
                    } elseif ($legacyPort['status'] === 'conflict') {
                        $result['conflict_ports']++;
                    }
                }

                self::saveMapping(
                    $batch,
                    self::LEGACY_PANELS,
                    $panelId,
                    PluginPatchpanelPanel::class,
                    $targetPanelId,
                    'imported',
                    sprintf(
                        __('Imported with %1$d partial and %2$d conflicting ports.', 'patchpanel'),
                        $legacy['counts']['partial'] + $legacy['counts']['invalid'],
                        $legacy['counts']['conflict']
                    )
                );
                $result['panels']++;
            }
            $DB->commit();
            return $result;
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    private static function saveMapping(
        string $batch,
        string $sourceTable,
        int $sourceId,
        string $targetItemtype,
        int $targetId,
        string $status,
        string $message
    ): void {
        global $DB;

        $table = 'glpi_plugin_patchpanel_migrations';
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $values = [
            'batch_uuid' => $batch,
            'target_itemtype' => $targetItemtype,
            'target_items_id' => $targetId,
            'status' => $status,
            'message' => $message,
            'date_mod' => $now,
        ];
        $existing = self::getMapping($sourceTable, $sourceId);
        if ($existing) {
            $DB->update($table, $values, ['id' => $existing['id']]);
            return;
        }
        $DB->insert($table, $values + [
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'date_creation' => $now,
        ]);
    }

    public static function getImportedBatches(): array
    {
        global $DB;

        $batches = [];
        foreach ($DB->request([
            'SELECT' => [
                'batch_uuid',
                'date_mod',
                'status',
                'source_table',
                'target_items_id',
            ],
            'FROM' => 'glpi_plugin_patchpanel_migrations',
            'WHERE' => [
                'NOT' => ['batch_uuid' => null],
                'status' => ['imported', 'imported_empty', 'imported_partial'],
            ],
            'ORDER' => ['date_mod DESC', 'id DESC'],
        ]) as $row) {
            $batch = (string) $row['batch_uuid'];
            if (!isset($batches[$batch])) {
                $batches[$batch] = [
                    'batch_uuid' => $batch,
                    'date_mod' => $row['date_mod'],
                    'panels' => 0,
                    'ports' => 0,
                ];
            }
            if ($row['source_table'] === self::LEGACY_PANELS) {
                $batches[$batch]['panels']++;
            } else {
                $batches[$batch]['ports']++;
            }
        }
        return array_values($batches);
    }

    public static function rollbackBatch(string $batch): int
    {
        global $DB;

        if (!preg_match('/^[a-f0-9]{32}$/', $batch)) {
            throw new InvalidArgumentException(__('Invalid migration batch.', 'patchpanel'));
        }

        $panelMappings = [];
        foreach ($DB->request([
            'FROM' => 'glpi_plugin_patchpanel_migrations',
            'WHERE' => [
                'batch_uuid' => $batch,
                'source_table' => self::LEGACY_PANELS,
                'status' => 'imported',
            ],
        ]) as $mapping) {
            $panelMappings[] = $mapping;
        }
        if (!$panelMappings) {
            throw new RuntimeException(__('No active imported panels were found for this batch.', 'patchpanel'));
        }

        $DB->beginTransaction();
        try {
            $count = 0;
            foreach ($panelMappings as $mapping) {
                $panel = new PluginPatchpanelPanel();
                $targetId = (int) $mapping['target_items_id'];
                if ($panel->getFromDB($targetId)) {
                    $lastMigrationDate = strtotime((string) ($mapping['date_mod'] ?? '')) ?: 0;
                    $panelDate = strtotime((string) ($panel->fields['date_mod'] ?? '')) ?: 0;
                    if ($panelDate > $lastMigrationDate) {
                        throw new RuntimeException(sprintf(
                            __('Imported panel #%d changed after migration; rollback was stopped.', 'patchpanel'),
                            $targetId
                        ));
                    }
                    $panel->check($targetId, PURGE);
                    if (!$panel->delete(['id' => $targetId], true)) {
                        throw new RuntimeException(__('Could not remove an imported panel.', 'patchpanel'));
                    }
                }
                $count++;
            }

            $DB->update('glpi_plugin_patchpanel_migrations', [
                'target_items_id' => 0,
                'status' => 'rolled_back',
                'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ], ['batch_uuid' => $batch]);
            $DB->commit();
            return $count;
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }
}
