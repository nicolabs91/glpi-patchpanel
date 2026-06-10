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
        ];

        foreach ($queries as $table => $query) {
            if (!$DB->tableExists($table)) {
                $DB->doQuery($query);
            }
        }

        self::seedModels();
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
}
