<?php

final class PluginPatchpanelQuality
{
    public const STATUSES = [
        'free',
        'partial',
        'connected',
        'warning',
        'disabled',
        'fault',
    ];

    public static function getStatusOptions(): array
    {
        return [
            '' => __('All statuses', 'patchpanel'),
            'free' => __('Free', 'patchpanel'),
            'partial' => __('Incomplete', 'patchpanel'),
            'connected' => __('Connected', 'patchpanel'),
            'warning' => __('Broken reference', 'patchpanel'),
            'disabled' => __('Out of service', 'patchpanel'),
            'fault' => __('Fault', 'patchpanel'),
        ];
    }

    public static function getReport(string $status = '', string $query = ''): array
    {
        global $DB;

        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $query = trim($query);
        $rows = [];
        $counts = array_fill_keys(self::STATUSES, 0);
        $entityCondition = getEntitiesRestrictCriteria(
            PluginPatchpanelPanel::getTable(),
            'entities_id',
            '',
            true
        );

        foreach ($DB->request([
            'SELECT' => [
                PluginPatchpanelPanelPort::getTable() . '.*',
                PluginPatchpanelPanel::getTable() . '.name AS panel_name',
                PluginPatchpanelPanel::getTable() . '.locations_id',
            ],
            'FROM' => PluginPatchpanelPanelPort::getTable(),
            'INNER JOIN' => [
                PluginPatchpanelPanel::getTable() => [
                    'FKEY' => [
                        PluginPatchpanelPanelPort::getTable() => 'plugin_patchpanel_panels_id',
                        PluginPatchpanelPanel::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                PluginPatchpanelPanel::getTable() . '.is_deleted' => 0,
                $entityCondition,
            ],
            'ORDER' => [
                PluginPatchpanelPanel::getTable() . '.name ASC',
                PluginPatchpanelPanelPort::getTable() . '.number ASC',
            ],
        ]) as $data) {
            $port = new PluginPatchpanelPanelPort();
            $port->fields = $data;
            $displayStatus = self::getStatus($port);
            $counts[$displayStatus]++;

            if ($status !== '' && $displayStatus !== $status) {
                continue;
            }
            if ($query !== '' && !self::matchesQuery($data, $query)) {
                continue;
            }

            $data['display_status'] = $displayStatus;
            $rows[] = $data;
        }

        return [
            'counts' => $counts,
            'rows' => $rows,
            'total' => array_sum($counts),
        ];
    }

    private static function getStatus(PluginPatchpanelPanelPort $port): string
    {
        $operationalState = (string) ($port->fields['operational_state'] ?? '');
        if ($operationalState === 'disabled') {
            return 'disabled';
        }
        if ($operationalState === 'fault') {
            return 'fault';
        }
        return $port->getDisplayStatus();
    }

    private static function matchesQuery(array $data, string $query): bool
    {
        $haystack = implode(' ', [
            $data['panel_name'] ?? '',
            $data['label'] ?? '',
            $data['number'] ?? '',
            $data['media'] ?? '',
        ]);
        return mb_stripos($haystack, $query) !== false;
    }

    public static function render(string $status = '', string $query = ''): void
    {
        global $CFG_GLPI;

        $report = self::getReport($status, $query);
        $options = self::getStatusOptions();
        $qualityUrl = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/quality.php';

        echo "<div class='container-fluid patchpanel-quality'>";
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<div><h1 class='h2 mb-1'>" . htmlescape(__('Cabling quality', 'patchpanel')) . '</h1>';
        echo "<p class='text-muted mb-0'>" .
            htmlescape(__('Find free ports and cabling records that need attention.', 'patchpanel')) .
            '</p></div>';
        echo "<a class='btn btn-outline-secondary ms-auto' href='" .
            htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panel.php') . "'>";
        echo "<i class='ti ti-layout-grid'></i> " . htmlescape(__('Patch panels', 'patchpanel')) . '</a>';
        echo "<a class='btn btn-outline-secondary' href='" .
            htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/routes.php') . "'>";
        echo "<i class='ti ti-route'></i> " . htmlescape(__('Route explorer', 'patchpanel')) . '</a>';
        echo '</div>';

        echo "<div class='patchpanel-quality-cards mb-3'>";
        foreach ($report['counts'] as $key => $count) {
            $active = $status === $key ? ' patchpanel-quality-card-active' : '';
            echo "<a class='patchpanel-quality-card patchpanel-status-" . htmlescape($key) . $active .
                "' href='" . htmlescape($qualityUrl . '?status=' . urlencode($key)) . "'>";
            echo "<i class='" . htmlescape(self::getStatusIcon($key)) . "'></i>";
            echo "<span>" . htmlescape($options[$key]) . '</span>';
            echo "<strong>" . (int) $count . '</strong></a>';
        }
        echo '</div>';

        echo "<form class='card card-body mb-3' method='get' action='" . htmlescape($qualityUrl) . "'>";
        echo "<div class='row g-2 align-items-end'>";
        echo "<div class='col-md-4'><label class='form-label'>" . htmlescape(__('Status')) . '</label>';
        Dropdown::showFromArray('status', $options, ['value' => $status]);
        echo '</div><div class="col-md-5"><label class="form-label">' .
            htmlescape(__('Panel, port or label', 'patchpanel')) . '</label>';
        echo Html::input('q', ['value' => $query]);
        echo '</div><div class="col-md-3 d-flex gap-2">';
        echo "<button class='btn btn-primary' type='submit'><i class='ti ti-filter'></i> " .
            htmlescape(__('Filter')) . '</button>';
        echo "<a class='btn btn-outline-secondary' href='" . htmlescape($qualityUrl) . "'>" .
            htmlescape(__('Reset')) . '</a></div></div></form>';

        echo "<div class='card'><div class='table-responsive'><table class='table table-hover card-table'>";
        echo '<thead><tr><th>' . htmlescape(__('Status')) . '</th><th>' .
            htmlescape(__('Patch panel', 'patchpanel')) . '</th><th>' .
            htmlescape(__('Port')) . '</th><th>' . htmlescape(__('Label')) . '</th><th>' .
            htmlescape(__('Media', 'patchpanel')) . '</th><th>' .
            htmlescape(__('Location')) . '</th><th></th></tr></thead><tbody>';
        if (!$report['rows']) {
            echo "<tr><td colspan='7' class='text-muted text-center py-4'>" .
                htmlescape(__('No ports match these filters.', 'patchpanel')) . '</td></tr>';
        }
        foreach ($report['rows'] as $row) {
            $portUrl = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panelport.form.php?id=' .
                (int) $row['id'];
            $panelUrl = PluginPatchpanelPanel::getFormURLWithID((int) $row['plugin_patchpanel_panels_id']);
            $location = Dropdown::getDropdownName('glpi_locations', (int) $row['locations_id']);
            $rowStatus = $row['display_status'];

            echo '<tr><td><span class="patchpanel-quality-status patchpanel-status-' .
                htmlescape($rowStatus) . '"><i class="' .
                htmlescape(self::getStatusIcon($rowStatus)) . '"></i> ' .
                htmlescape($options[$rowStatus]) . '</span></td>';
            echo "<td><a href='" . htmlescape($panelUrl) . "'>" .
                htmlescape($row['panel_name']) . '</a></td>';
            echo "<td><a href='" . htmlescape($portUrl) . "'>#" . (int) $row['number'] . '</a></td>';
            echo '<td>' . htmlescape($row['label'] ?: '-') . '</td>';
            echo '<td>' . htmlescape(
                PluginPatchpanelPanelPort::getMediaOptions()[$row['media']] ?? $row['media']
            ) . '</td>';
            echo '<td>' . htmlescape($location ?: '-') . '</td>';
            echo "<td class='text-end'><a class='btn btn-sm btn-outline-primary' href='" .
                htmlescape($portUrl) . "'>" . htmlescape(__('Open route', 'patchpanel')) .
                '</a></td></tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    private static function getStatusIcon(string $status): string
    {
        return $status === 'fault'
            ? 'ti ti-tool'
            : PluginPatchpanelPanelPort::getStatusIcon($status);
    }
}
