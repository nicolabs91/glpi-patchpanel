<?php

final class PluginPatchpanelHealth
{
    private const EXPECTED_INDEXES = [
        ['glpi_plugin_patchpanel_portendpoints', 'port_side', ['plugin_patchpanel_panelports_id', 'side']],
        ['glpi_plugin_patchpanel_portendpoints', 'endpoint', ['itemtype', 'items_id']],
        ['glpi_plugin_patchpanel_panelports', 'panel_number', ['plugin_patchpanel_panels_id', 'number']],
        ['glpi_plugin_patchpanel_panelports', 'panel_layout', ['plugin_patchpanel_panels_id', 'row', 'position']],
        ['glpi_networkports', 'item', ['itemtype', 'items_id']],
        ['glpi_sockets', 'item', ['itemtype', 'items_id']],
        ['glpi_sockets', 'networkports_id', ['networkports_id']],
    ];

    public static function getReport(): array
    {
        return [
            'indexes' => self::checkIndexes(),
            'integrity' => self::checkIntegrity(),
        ];
    }

    public static function render(): void
    {
        global $CFG_GLPI;

        $report = self::getReport();
        $allOk = self::isHealthy($report);
        $healthUrl = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/health.php';

        echo "<div class='container-fluid patchpanel-health'>";
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<div><h1 class='h2 mb-1'>" . htmlescape(__('PatchPanel health check', 'patchpanel')) . '</h1>';
        echo "<p class='text-muted mb-0'>" .
            htmlescape(__('Verify database integrity, route indexes and import safety before release or upload.', 'patchpanel')) .
            '</p></div>';
        echo "<a class='btn btn-outline-secondary ms-auto' href='" .
            htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panel.php') . "'>";
        echo "<i class='ti ti-layout-grid'></i> " . htmlescape(__('Patch panels', 'patchpanel')) . '</a>';
        echo "<a class='btn btn-outline-secondary' href='" . htmlescape($healthUrl) . "'>";
        echo "<i class='ti ti-refresh'></i> " . htmlescape(__('Run again', 'patchpanel')) . '</a>';
        echo '</div>';

        echo "<div class='alert " . ($allOk ? 'alert-success' : 'alert-warning') . " d-flex gap-2'>";
        echo "<i class='" . ($allOk ? 'ti ti-circle-check' : 'ti ti-alert-triangle') . "'></i><div>";
        echo htmlescape($allOk
            ? __('PatchPanel data is healthy.', 'patchpanel')
            : __('PatchPanel found issues that should be fixed before release.', 'patchpanel'));
        echo '</div></div>';

        self::renderSection(__('Performance indexes', 'patchpanel'), $report['indexes']);
        self::renderSection(__('Data integrity', 'patchpanel'), $report['integrity']);
        echo '</div>';
    }

    private static function renderSection(string $title, array $checks): void
    {
        echo "<section class='card mb-3'><div class='card-header'><h2 class='card-title mb-0'>" .
            htmlescape($title) . '</h2></div>';
        echo "<div class='table-responsive'><table class='table table-hover card-table'>";
        echo '<thead><tr><th>' . htmlescape(__('Check', 'patchpanel')) . '</th><th>' .
            htmlescape(__('Status', 'patchpanel')) . '</th><th>' .
            htmlescape(__('Result', 'patchpanel')) . '</th><th>' .
            htmlescape(__('Repair suggestion', 'patchpanel')) . '</th></tr></thead><tbody>';
        foreach ($checks as $check) {
            $ok = (bool) $check['ok'];
            echo '<tr><td>' . htmlescape($check['label']) . '</td><td>';
            echo "<span class='patchpanel-quality-status patchpanel-status-" . ($ok ? 'connected' : 'warning') . "'>";
            echo "<i class='" . ($ok ? 'ti ti-circle-check' : 'ti ti-alert-triangle') . "'></i> " .
                htmlescape($ok ? __('OK', 'patchpanel') : __('Needs attention', 'patchpanel')) . '</span>';
            echo '</td><td>' . htmlescape($check['result']) . '</td><td>' .
                htmlescape($check['suggestion']) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private static function checkIndexes(): array
    {
        $checks = [];
        foreach (self::EXPECTED_INDEXES as [$table, $index, $columns]) {
            $exists = self::hasIndex($table, $index, $columns);
            $checks[] = [
                'label' => sprintf('%s.%s', $table, $index),
                'ok' => $exists,
                'result' => $exists ? __('Present', 'patchpanel') : __('Missing', 'patchpanel'),
                'suggestion' => $exists
                    ? __('No action needed.', 'patchpanel')
                    : __('Run the plugin install/upgrade schema step before using this dataset.', 'patchpanel'),
            ];
        }
        return $checks;
    }

    private static function checkIntegrity(): array
    {
        return [
            self::countCheck(
                __('Endpoint rows without a panel port', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_portendpoints e
                 LEFT JOIN glpi_plugin_patchpanel_panelports p
                   ON p.id = e.plugin_patchpanel_panelports_id
                 WHERE p.id IS NULL",
                __('Remove orphan endpoint rows or restore the missing panel port before routing.', 'patchpanel')
            ),
            self::countCheck(
                __('Panel ports without a panel', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_panelports p
                 LEFT JOIN glpi_plugin_patchpanel_panels pa
                   ON pa.id = p.plugin_patchpanel_panels_id
                 WHERE pa.id IS NULL",
                __('Remove orphan panel ports or restore the parent panel.', 'patchpanel')
            ),
            self::countCheck(
                __('Duplicate port sides', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM (
                   SELECT plugin_patchpanel_panelports_id, side, COUNT(*) AS duplicate_count
                   FROM glpi_plugin_patchpanel_portendpoints
                   GROUP BY plugin_patchpanel_panelports_id, side
                   HAVING duplicate_count > 1
                 ) duplicates",
                __('Keep one rear and one front endpoint per panel port.', 'patchpanel')
            ),
            self::countCheck(
                __('Endpoint reused by multiple ports', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM (
                   SELECT itemtype, items_id, COUNT(*) AS duplicate_count
                   FROM glpi_plugin_patchpanel_portendpoints
                   GROUP BY itemtype, items_id
                   HAVING duplicate_count > 1
                 ) duplicates",
                __('Move duplicate endpoint assignments so each GLPI socket or network port is used once.', 'patchpanel')
            ),
            self::countCheck(
                __('Invalid endpoint side or type', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_portendpoints
                 WHERE (side = 'rear' AND itemtype <> 'Glpi\\\\Socket')
                    OR (side = 'front' AND itemtype <> 'NetworkPort')
                    OR side NOT IN ('rear', 'front')",
                __('Rear endpoints must be GLPI sockets; front endpoints must be GLPI network ports.', 'patchpanel')
            ),
            self::countCheck(
                __('Broken socket references', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_portendpoints e
                 LEFT JOIN glpi_sockets s ON s.id = e.items_id
                 WHERE e.itemtype = 'Glpi\\\\Socket'
                   AND s.id IS NULL",
                __('Reconnect the rear side to an existing GLPI socket or clear the endpoint.', 'patchpanel')
            ),
            self::countCheck(
                __('Broken network port references', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_portendpoints e
                 LEFT JOIN glpi_networkports np ON np.id = e.items_id
                 WHERE e.itemtype = 'NetworkPort'
                   AND (np.id IS NULL OR np.is_deleted <> 0)",
                __('Reconnect the front side to an existing GLPI network port or clear the endpoint.', 'patchpanel')
            ),
            self::countCheck(
                __('Invalid panel port state or media', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_panelports
                 WHERE operational_state NOT IN ('active', 'reserved', 'fault', 'disabled')
                    OR media NOT IN ('copper', 'fiber-sm', 'fiber-mm', 'other')",
                __('Normalize panel port state and media values before rendering panel and health views.', 'patchpanel')
            ),
            self::countCheck(
                __('Invalid panel or port layout numbers', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_panels pa
                 LEFT JOIN glpi_plugin_patchpanel_panelports p
                   ON p.plugin_patchpanel_panels_id = pa.id
                 WHERE pa.port_count < 1
                    OR pa.rows < 1
                    OR p.number < 1
                    OR p.row < 1
                    OR p.position < 1",
                __('Use positive panel dimensions and port layout numbers before route navigation.', 'patchpanel')
            ),
            self::countCheck(
                __('Applied CSV batches without changes', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM (
                   SELECT b.id
                   FROM glpi_plugin_patchpanel_importbatches b
                   LEFT JOIN glpi_plugin_patchpanel_importchanges c
                     ON c.batch_uuid = b.batch_uuid
                   WHERE b.status = 'applied'
                   GROUP BY b.id
                   HAVING COUNT(c.id) = 0
                 ) empty_batches",
                __('Rollback or remove empty applied batches before relying on CSV rollback history.', 'patchpanel')
            ),
        ];
    }

    private static function countCheck(string $label, string $sql, string $suggestion): array
    {
        $count = self::scalar($sql);
        return [
            'label' => $label,
            'ok' => $count === 0,
            'result' => sprintf(__('%d found', 'patchpanel'), $count),
            'suggestion' => $count === 0 ? __('No action needed.', 'patchpanel') : $suggestion,
        ];
    }

    private static function hasIndex(string $table, string $index, array $columns): bool
    {
        global $DB;

        if (!$DB->tableExists($table)) {
            return false;
        }
        $actual = [];
        $result = $DB->doQuery('SHOW INDEX FROM `' . $DB->escape($table) . '`');
        while ($result && ($row = $result->fetch_assoc())) {
            if (($row['Key_name'] ?? '') === $index) {
                $actual[(int) $row['Seq_in_index']] = $row['Column_name'];
            }
        }
        ksort($actual);
        return array_values($actual) === $columns;
    }

    private static function scalar(string $sql): int
    {
        global $DB;

        $result = $DB->doQuery($sql);
        $row = $result ? $result->fetch_assoc() : null;
        return (int) ($row['count'] ?? 0);
    }

    private static function isHealthy(array $report): bool
    {
        foreach ($report as $checks) {
            foreach ($checks as $check) {
                if (empty($check['ok'])) {
                    return false;
                }
            }
        }
        return true;
    }
}
