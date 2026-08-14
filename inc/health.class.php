<?php

final class PluginPatchpanelHealth
{
    private const EXPECTED_INDEXES = [
        ['glpi_plugin_patchpanel_portendpoints', 'port_side', ['plugin_patchpanel_panelports_id', 'side']],
        ['glpi_plugin_patchpanel_portendpoints', 'endpoint', ['itemtype', 'items_id']],
        ['glpi_plugin_patchpanel_panelports', 'panel_number', ['plugin_patchpanel_panels_id', 'number']],
        ['glpi_plugin_patchpanel_panelports', 'panel_layout', ['plugin_patchpanel_panels_id', 'row', 'position']],
        ['glpi_plugin_patchpanel_panelportlinks', 'link_pair', ['panelports_id_a', 'panelports_id_b']],
        ['glpi_plugin_patchpanel_panelportlinks', 'panelports_id_a', ['panelports_id_a']],
        ['glpi_plugin_patchpanel_panelportlinks', 'panelports_id_b', ['panelports_id_b']],
        ['glpi_plugin_patchpanel_panelportlinks', 'is_active', ['is_active']],
        ['glpi_plugin_patchpanel_impactrelations', 'impact_relation', ['impactrelations_id']],
        ['glpi_plugin_patchpanel_impactrelations', 'managed_edge', ['itemtype_source', 'items_id_source', 'itemtype_impacted', 'items_id_impacted']],
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
                __('Managed impact relations without a matching GLPI relation', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_impactrelations managed
                 LEFT JOIN glpi_impactrelations native
                   ON native.id = managed.impactrelations_id
                  AND native.itemtype_source = managed.itemtype_source
                  AND native.items_id_source = managed.items_id_source
                  AND native.itemtype_impacted = managed.itemtype_impacted
                  AND native.items_id_impacted = managed.items_id_impacted
                 WHERE native.id IS NULL",
                __('Run PatchPanel impact synchronization to repair stale ownership records.', 'patchpanel')
            ),
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
                __('Panel links with a missing port', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_panelportlinks l
                 LEFT JOIN glpi_plugin_patchpanel_panelports a ON a.id = l.panelports_id_a
                 LEFT JOIN glpi_plugin_patchpanel_panelports b ON b.id = l.panelports_id_b
                 WHERE a.id IS NULL OR b.id IS NULL",
                __('Remove the orphan panel link or restore both linked panel ports.', 'patchpanel')
            ),
            self::countCheck(
                __('Panel links attached to a deleted panel', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_panelportlinks l
                 INNER JOIN glpi_plugin_patchpanel_panelports a ON a.id = l.panelports_id_a
                 INNER JOIN glpi_plugin_patchpanel_panelports b ON b.id = l.panelports_id_b
                 INNER JOIN glpi_plugin_patchpanel_panels pa
                   ON pa.id = a.plugin_patchpanel_panels_id
                 INNER JOIN glpi_plugin_patchpanel_panels pb
                   ON pb.id = b.plugin_patchpanel_panels_id
                 WHERE l.is_active = 1
                   AND (pa.is_deleted <> 0 OR pb.is_deleted <> 0)",
                __('Restore the parent panel or remove its active panel-to-panel link.', 'patchpanel')
            ),
            self::countCheck(
                __('Self-linked or non-normalized panel links', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_panelportlinks
                 WHERE panelports_id_a >= panelports_id_b",
                __('Recreate the link with two different ports in canonical endpoint order.', 'patchpanel')
            ),
            self::countCheck(
                __('Panel ports used by multiple active links', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM (
                   SELECT panelport_id
                   FROM (
                     SELECT panelports_id_a AS panelport_id
                     FROM glpi_plugin_patchpanel_panelportlinks
                     WHERE is_active = 1
                     UNION ALL
                     SELECT panelports_id_b AS panelport_id
                     FROM glpi_plugin_patchpanel_panelportlinks
                     WHERE is_active = 1
                   ) endpoints
                   GROUP BY panelport_id
                   HAVING COUNT(*) > 1
                 ) duplicate_endpoints",
                __('Keep at most one active panel-to-panel link for each panel port.', 'patchpanel')
            ),
            self::countCheck(
                __('Panel links conflicting with rear sockets', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_panelportlinks l
                 INNER JOIN glpi_plugin_patchpanel_portendpoints e
                   ON e.plugin_patchpanel_panelports_id IN (l.panelports_id_a, l.panelports_id_b)
                  AND e.side = 'rear'
                  AND e.itemtype = 'Glpi\\\\Socket'
                  AND e.items_id > 0
                 WHERE l.is_active = 1",
                __('Disconnect the rear socket or remove the conflicting panel-to-panel link.', 'patchpanel')
            ),
            self::countCheck(
                __('Hidden GLPI ports without a panel port', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_networkports np
                 LEFT JOIN glpi_plugin_patchpanel_panelports p
                   ON p.id = np.items_id
                 WHERE np.itemtype = 'PluginPatchpanelPanelPort'
                   AND np.is_deleted = 0
                   AND p.id IS NULL",
                __('Purge orphan hidden GLPI ports after backing up the affected rows.', 'patchpanel')
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
            self::frontEndpointOwnerCheck(),
            self::countCheck(
                __('Front endpoints attached to a deleted panel', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_portendpoints e
                 INNER JOIN glpi_plugin_patchpanel_panelports p
                   ON p.id = e.plugin_patchpanel_panelports_id
                 INNER JOIN glpi_plugin_patchpanel_panels pa
                   ON pa.id = p.plugin_patchpanel_panels_id
                 WHERE e.side = 'front'
                   AND pa.is_deleted <> 0",
                __('Restore the parent panel or remove the stale front endpoint.', 'patchpanel')
            ),
            self::countCheck(
                __('Invalid native links involving PatchPanel ports', 'patchpanel'),
                "SELECT COUNT(DISTINCT nn.id) AS count
                 FROM glpi_networkports_networkports nn
                 INNER JOIN glpi_networkports a ON a.id = nn.networkports_id_1
                 INNER JOIN glpi_networkports b ON b.id = nn.networkports_id_2
                 LEFT JOIN glpi_plugin_patchpanel_panelports ppa
                   ON a.itemtype = 'PluginPatchpanelPanelPort' AND ppa.id = a.items_id
                 LEFT JOIN glpi_plugin_patchpanel_panels pa
                   ON pa.id = ppa.plugin_patchpanel_panels_id
                 LEFT JOIN glpi_plugin_patchpanel_panelports ppb
                   ON b.itemtype = 'PluginPatchpanelPanelPort' AND ppb.id = b.items_id
                 LEFT JOIN glpi_plugin_patchpanel_panels pb
                   ON pb.id = ppb.plugin_patchpanel_panels_id
                 WHERE (a.itemtype = 'PluginPatchpanelPanelPort'
                        OR b.itemtype = 'PluginPatchpanelPanelPort')
                   AND (
                     (a.itemtype = 'PluginPatchpanelPanelPort'
                      AND b.itemtype = 'PluginPatchpanelPanelPort')
                     OR a.is_deleted <> 0 OR b.is_deleted <> 0
                     OR (a.itemtype = 'PluginPatchpanelPanelPort'
                         AND (ppa.id IS NULL OR pa.id IS NULL OR pa.is_deleted <> 0))
                     OR (b.itemtype = 'PluginPatchpanelPanelPort'
                         AND (ppb.id IS NULL OR pb.id IS NULL OR pb.is_deleted <> 0))
                   )",
                __('Disconnect the invalid GLPI Connected to relation and reconnect only an active panel port to a real network port.', 'patchpanel')
            ),
            self::countCheck(
                __('Missing native network port links', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM (
                   SELECT
                     front.items_id AS front_port_id,
                     COALESCE(shadow.id, 0) AS target_port_id
                   FROM glpi_plugin_patchpanel_panelports p
                   INNER JOIN glpi_plugin_patchpanel_portendpoints front
                     ON front.plugin_patchpanel_panelports_id = p.id
                    AND front.side = 'front'
                    AND front.itemtype = 'NetworkPort'
                   INNER JOIN glpi_networkports np
                     ON np.id = front.items_id
                    AND np.is_deleted = 0
                   LEFT JOIN glpi_plugin_patchpanel_portendpoints rear
                     ON rear.plugin_patchpanel_panelports_id = p.id
                    AND rear.side = 'rear'
                    AND rear.itemtype = 'Glpi\\\\Socket'
                   LEFT JOIN glpi_sockets s
                     ON s.id = rear.items_id
                   LEFT JOIN glpi_networkports shadow
                     ON shadow.itemtype = 'PluginPatchpanelPanelPort'
                    AND shadow.items_id = p.id
                    AND shadow.is_deleted = 0
                 ) expected
                 LEFT JOIN glpi_networkports_networkports nn
                   ON (
                     nn.networkports_id_1 = expected.front_port_id
                     AND nn.networkports_id_2 = expected.target_port_id
                   ) OR (
                     nn.networkports_id_2 = expected.front_port_id
                     AND nn.networkports_id_1 = expected.target_port_id
                   )
                 WHERE expected.target_port_id > 0
                   AND nn.id IS NULL",
                __('Save the affected panel port or socket again so GLPI Connected to matches PatchPanel.', 'patchpanel')
            ),
            self::countCheck(
                __('Invalid panel port state or media', 'patchpanel'),
                "SELECT COUNT(*) AS count
                 FROM glpi_plugin_patchpanel_panelports
                 WHERE operational_state NOT IN ('active', 'reserved')
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

    private static function frontEndpointOwnerCheck(): array
    {
        global $DB;

        $count = 0;
        foreach ($DB->request([
            'SELECT' => ['items_id'],
            'FROM' => PluginPatchpanelPortEndpoint::getTable(),
            'WHERE' => [
                'side' => PluginPatchpanelPortEndpoint::FRONT,
                'itemtype' => NetworkPort::class,
            ],
        ]) as $endpoint) {
            $networkPort = new NetworkPort();
            $networkPortId = (int) $endpoint['items_id'];
            if (
                $networkPort->getFromDB($networkPortId)
                && (int) ($networkPort->fields['is_deleted'] ?? 0) === 0
                && !PluginPatchpanelPortEndpoint::isUsableFrontNetworkPortId($networkPortId)
            ) {
                $count++;
            }
        }
        return [
            'label' => __('Front endpoints owned by deleted or missing items', 'patchpanel'),
            'ok' => $count === 0,
            'result' => sprintf(__('%d found', 'patchpanel'), $count),
            'suggestion' => $count === 0
                ? __('No action needed.', 'patchpanel')
                : __('Remove the stale endpoint, then connect a port owned by an active GLPI item.', 'patchpanel'),
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
