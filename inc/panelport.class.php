<?php

class PluginPatchpanelPanelPort extends CommonDBChild
{
    public static $itemtype = 'PluginPatchpanelPanel';
    public static $items_id = 'plugin_patchpanel_panels_id';
    public static $rightname = 'networking';
    public $dohistory = true;

    public static function getTypeName($nb = 0): string
    {
        return _n('Panel port', 'Panel ports', $nb, 'patchpanel');
    }

    public function getName($options = [])
    {
        $portNumber = (int) ($this->fields['number'] ?? $this->getID());
        $portName = sprintf(__('Port %d', 'patchpanel'), $portNumber);

        $panel = new PluginPatchpanelPanel();
        if ($panel->getFromDB((int) ($this->fields['plugin_patchpanel_panels_id'] ?? 0))) {
            return sprintf('%s / %s', $panel->getName(), $portName);
        }

        return $portName;
    }

    public static function getMediaOptions(): array
    {
        return [
            'copper' => __('Copper', 'patchpanel'),
            'fiber-sm' => __('Single-mode fiber', 'patchpanel'),
            'fiber-mm' => __('Multimode fiber', 'patchpanel'),
            'other' => __('Other'),
        ];
    }

    public static function getOperationalStateOptions(bool $includeEmpty = false): array
    {
        $options = [
            'active' => __('Active'),
            'reserved' => __('Reserved', 'patchpanel'),
        ];
        return $includeEmpty ? ['' => __('Keep current value', 'patchpanel')] + $options : $options;
    }

    public static function normalizeMedia(string $media): string
    {
        return array_key_exists($media, self::getMediaOptions()) ? $media : 'other';
    }

    public static function synchronizeForPanel(
        PluginPatchpanelPanel $panel,
        bool $updateExistingMedia = false
    ): void
    {
        global $DB;

        $panelId = (int) $panel->getID();
        if ($panelId <= 0) {
            return;
        }

        $count = max(1, (int) $panel->fields['port_count']);
        $rows = max(1, (int) $panel->fields['rows']);
        $perRow = (int) ceil($count / $rows);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $existing = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'number'],
            'FROM' => self::getTable(),
            'WHERE' => ['plugin_patchpanel_panels_id' => $panelId],
        ]) as $row) {
            $existing[(int) $row['number']] = (int) $row['id'];
        }

        $DB->beginTransaction();
        try {
            for ($number = 1; $number <= $count; $number++) {
                $layout = [
                    'row' => (int) floor(($number - 1) / $perRow) + 1,
                    'position' => (($number - 1) % $perRow) + 1,
                    'date_mod' => $now,
                ];
                if (isset($existing[$number])) {
                    if ($updateExistingMedia) {
                        $layout['media'] = $panel->fields['media'];
                    }
                    $DB->update(self::getTable(), $layout, ['id' => $existing[$number]]);
                    continue;
                }
                $DB->insert(self::getTable(), $layout + [
                    'plugin_patchpanel_panels_id' => $panelId,
                    'number' => $number,
                    'label' => sprintf(__('Patch port %02d', 'patchpanel'), $number),
                    'operational_state' => 'active',
                    'media' => $panel->fields['media'],
                    'date_creation' => $now,
                ]);
            }

            $extraIds = [];
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => self::getTable(),
                'WHERE' => [
                    'plugin_patchpanel_panels_id' => $panelId,
                    'number' => ['>', $count],
                ],
            ]) as $row) {
                $extraIds[] = (int) $row['id'];
            }
            if ($extraIds) {
                $connectedIds = [];
                foreach ($DB->request([
                    'SELECT' => ['plugin_patchpanel_panelports_id'],
                    'FROM' => PluginPatchpanelPortEndpoint::getTable(),
                    'WHERE' => ['plugin_patchpanel_panelports_id' => $extraIds],
                ]) as $endpoint) {
                    $connectedIds[] = (int) $endpoint['plugin_patchpanel_panelports_id'];
                }
                $deletableIds = array_values(array_diff($extraIds, array_unique($connectedIds)));
                if ($deletableIds) {
                    PluginPatchpanelPortEndpoint::cleanupPanelNetworkPortsForPanelPorts($deletableIds);
                    $DB->delete(self::getTable(), ['id' => $deletableIds]);
                }
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            Toolbox::logInFile(
                'php-errors',
                'PatchPanel port synchronization failed: ' . $e->getMessage() . "\n"
            );
            Session::addMessageAfterRedirect(
                __('The panel was saved, but its ports could not be synchronized.', 'patchpanel'),
                false,
                ERROR
            );
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof PluginPatchpanelPanel) {
            return '';
        }
        $count = $_SESSION['glpishow_count_on_tabs']
            ? countElementsInTable(self::getTable(), ['plugin_patchpanel_panels_id' => $item->getID()])
            : 0;
        return self::createTabEntry(__('Visual panel', 'patchpanel'), $count);
    }

    public function cleanDBonPurge()
    {
        global $DB;

        PluginPatchpanelPortEndpoint::cleanupPanelNetworkPortsForPanelPorts([
            (int) $this->getID(),
        ]);
        $DB->delete(PluginPatchpanelPortEndpoint::getTable(), [
            'plugin_patchpanel_panelports_id' => $this->getID(),
        ]);
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        if (!$item instanceof PluginPatchpanelPanel) {
            return false;
        }
        self::showVisualPanel($item);
        return true;
    }

    public static function showVisualPanel(PluginPatchpanelPanel $panel): void
    {
        global $DB, $CFG_GLPI;

        $ports = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['plugin_patchpanel_panels_id' => $panel->getID()],
            'ORDER' => ['row ASC', 'position ASC', 'number ASC'],
        ]);

        self::showPanelOverview($panel);

        echo "<div class='patchpanel-legend' aria-label='" . htmlescape(__('Status legend', 'patchpanel')) . "'>";
        foreach ([
            'free' => __('Free', 'patchpanel'),
            'partial' => __('Not connected', 'patchpanel'),
            'connected' => __('Connected', 'patchpanel'),
        ] as $status => $label) {
            echo "<span class='patchpanel-legend-item patchpanel-status-$status'>";
            echo "<i class='" . self::getStatusIcon($status) . "'></i> " . htmlescape($label);
            echo '</span>';
        }
        echo '</div>';

        echo "<div class='d-flex flex-wrap justify-content-end gap-2 mb-3'>";
        echo "<a class='btn btn-outline-secondary' href='" .
            htmlescape(
                $CFG_GLPI['root_doc'] .
                '/plugins/patchpanel/front/routes.php?q=' .
                rawurlencode((string) $panel->fields['name'])
            ) . "'>";
        echo "<i class='ti ti-route'></i> " .
            htmlescape(__('Search routes', 'patchpanel')) . '</a>';
        echo "<a class='btn btn-outline-secondary me-2' href='" .
            htmlescape(
                $CFG_GLPI['root_doc'] .
                '/plugins/patchpanel/front/audit.php?panel_id=' .
                (int) $panel->getID()
            ) . "'>";
        echo "<i class='ti ti-history'></i> " .
            htmlescape(__('Audit history', 'patchpanel')) . '</a></div>';

        $columns = (int) ceil(
            (int) $panel->fields['port_count'] / max(1, (int) $panel->fields['rows'])
        );
        $columns = min(24, max(6, $columns));
        echo "<div class='patchpanel-grid' style='--patchpanel-columns:$columns'>";
        foreach ($ports as $data) {
            $url = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panelport.form.php?id=' . (int) $data['id'];
            $route = PluginPatchpanelRoute::buildForPort((int) $data['id']);
            $status = self::getDisplayStatusFromRoute($data, $route);
            $title = trim((string) ($data['label'] ?? '')) ?: sprintf(__('Port %d', 'patchpanel'), $data['number']);

            echo "<a class='patchpanel-port patchpanel-status-" . htmlescape($status) .
                "' href='" . htmlescape($url) . "' aria-label='" .
                htmlescape($title . ': ' . self::getStatusLabel($status)) . "'>";
            echo "<span class='patchpanel-port-number'>" . (int) $data['number'] . '</span>';
            echo "<span class='patchpanel-port-icon'><i class='" . self::getStatusIcon($status) . "'></i></span>";
            echo "<span class='patchpanel-port-label'>" . htmlescape($title) . '</span>';
            if (!empty($route['front']['cable_color'])) {
                echo "<span class='patchpanel-cable-color' style='--cable-color:" .
                    htmlescape($route['front']['cable_color']) . "' title='" .
                    htmlescape(__('Patch cable color', 'patchpanel')) . "'></span>";
            }
            echo '</a>';
        }
        echo '</div>';

    }

    private static function showPanelOverview(PluginPatchpanelPanel $panel): void
    {
        $counts = self::getStatusCountsForPanel((int) $panel->getID());
        $total = max(1, (int) $panel->fields['port_count']);
        $connected = (int) ($counts['connected'] ?? 0);
        $notConnected = (int) ($counts['partial'] ?? 0);

        echo "<section class='patchpanel-panel-overview mb-3'>";
        foreach ([
            'connected' => [
                __('Connected', 'patchpanel'),
                $connected,
                sprintf(__('%d%% patched', 'patchpanel'), (int) round(($connected / $total) * 100)),
            ],
            'free' => [
                __('Free', 'patchpanel'),
                (int) ($counts['free'] ?? 0),
                __('Available ports', 'patchpanel'),
            ],
            'partial' => [
                __('Not connected', 'patchpanel'),
                $notConnected,
                __('One side is not linked yet', 'patchpanel'),
            ],
        ] as $status => [$label, $count, $hint]) {
            echo "<div class='patchpanel-overview-card patchpanel-status-" . htmlescape($status) . "'>";
            echo "<span><i class='" . htmlescape(self::getStatusIcon($status)) . "'></i> " .
                htmlescape($label) . '</span>';
            echo '<strong>' . (int) $count . '</strong>';
            echo '<small>' . htmlescape($hint) . '</small></div>';
        }
        echo '</section>';
    }

    private static function getStatusCountsForPanel(int $panelId): array
    {
        $counts = [
            'free' => 0,
            'partial' => 0,
            'connected' => 0,
        ];
        foreach (self::getDisplayStatusMapForRows(self::getPanelStatusRows($panelId)) as $status) {
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        return $counts;
    }

    private static function getPanelStatusRows(int $panelId): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'operational_state'],
            'FROM' => self::getTable(),
            'WHERE' => ['plugin_patchpanel_panels_id' => $panelId],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getDisplayStatus(): string
    {
        $route = PluginPatchpanelRoute::buildForPort((int) $this->fields['id']);
        return self::getDisplayStatusFromRoute($this->fields, $route);
    }

    public static function getDisplayStatusMapForRows(array $rows): array
    {
        $counts = self::getEndpointCountsForPortRows($rows);
        $statuses = [];
        foreach ($rows as $row) {
            $portId = (int) ($row['id'] ?? 0);
            if ($portId <= 0) {
                continue;
            }
            $statuses[$portId] = self::getDisplayStatusFromCounts(
                (string) ($row['operational_state'] ?? ''),
                (int) ($counts[$portId]['endpoint_count'] ?? 0),
                (int) ($counts[$portId]['broken_count'] ?? 0),
                (int) ($counts[$portId]['complete_count'] ?? 0)
            );
        }
        return $statuses;
    }

    private static function getEndpointCountsForPortRows(array $rows): array
    {
        global $DB;

        $portIds = array_values(array_filter(array_map(
            static fn($row) => (int) ($row['id'] ?? 0),
            $rows
        )));
        if (!$portIds) {
            return [];
        }

        $endpointTable = PluginPatchpanelPortEndpoint::getTable();
        $socketType = $DB->escape(\Glpi\Socket::class);
        $networkPortType = $DB->escape(NetworkPort::class);
        $sql = "SELECT e.plugin_patchpanel_panelports_id AS port_id,
                       COUNT(e.id) AS endpoint_count,
                       SUM(
                           CASE
                               WHEN e.itemtype = '$socketType' AND s.id IS NULL THEN 1
                               WHEN e.itemtype = '$networkPortType' AND (np.id IS NULL OR np.is_deleted <> 0) THEN 1
                               WHEN e.itemtype NOT IN ('$socketType', '$networkPortType') THEN 1
                               ELSE 0
                           END
                       ) AS broken_count,
                       SUM(CASE WHEN e.side = 'rear' AND s.networkports_id > 0 THEN 1 ELSE 0 END)
                           AS rear_terminal_count,
                       SUM(CASE WHEN e.side = 'front' AND np.id IS NOT NULL AND np.is_deleted = 0 THEN 1 ELSE 0 END)
                           AS front_count
                FROM `$endpointTable` e
                LEFT JOIN `glpi_sockets` s
                    ON e.itemtype = '$socketType' AND s.id = e.items_id
                LEFT JOIN `glpi_networkports` np
                    ON e.itemtype = '$networkPortType' AND np.id = e.items_id
                WHERE e.plugin_patchpanel_panelports_id IN (" . implode(',', $portIds) . ")
                GROUP BY e.plugin_patchpanel_panelports_id";

        $counts = [];
        $result = $DB->doQuery($sql);
        while ($result && ($row = $result->fetch_assoc())) {
            $counts[(int) $row['port_id']] = [
                'endpoint_count' => (int) ($row['endpoint_count'] ?? 0),
                'broken_count' => (int) ($row['broken_count'] ?? 0),
                'complete_count' => min(
                    (int) ($row['rear_terminal_count'] ?? 0),
                    (int) ($row['front_count'] ?? 0)
                ),
            ];
        }
        return $counts;
    }

    private static function getDisplayStatusFromRoute(array $fields, array $route): string
    {
        $count = (int) isset($route['rear']) + (int) isset($route['front']);
        if ($count === 0) {
            return 'free';
        }
        return $route['rear'] && $route['front'] && $route['terminal'] ? 'connected' : 'partial';
    }

    private static function getDisplayStatusFromCounts(
        string $operationalState,
        int $endpointCount,
        int $brokenCount,
        int $completeCount
    ): string {
        if ($endpointCount === 0) {
            return 'free';
        }
        return $completeCount > 0 ? 'connected' : 'partial';
    }

    public static function getStatusIcon(string $status): string
    {
        return match ($status) {
            'connected' => 'ti ti-circle-check',
            'attention' => 'ti ti-alert-circle',
            'partial' => 'ti ti-circle-dashed',
            default => 'ti ti-circle-dashed',
        };
    }

    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'connected' => __('Connected', 'patchpanel'),
            'partial' => __('Not connected', 'patchpanel'),
            default => __('Free', 'patchpanel'),
        };
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'><td>" . __('Port number', 'patchpanel') . "</td><td>";
        echo (int) ($this->fields['number'] ?? 0);
        echo '</td><td>' . __('Label') . '</td><td>';
        echo Html::input('label', ['value' => $this->fields['label'] ?? '']);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __('Operational state', 'patchpanel') . "</td><td>";
        Dropdown::showFromArray('operational_state', self::getOperationalStateOptions(), [
            'value' => $this->fields['operational_state'] ?? 'active',
        ]);
        echo '</td><td>' . __('Media', 'patchpanel') . '</td><td>';
        Dropdown::showFromArray('media', self::getMediaOptions(), [
            'value' => $this->fields['media'] ?? 'copper',
        ]);
        echo '</td></tr>';

        $this->showPortWorkflowRow();

        PluginPatchpanelPortEndpoint::showEndpointFields((int) $this->getID());
        echo "<tr class='tab_bg_1'><td>" . _n('Comment', 'Comments', 1) . "</td><td colspan='3'>";
        Html::textarea([
            'name' => 'comment',
            'value' => $this->fields['comment'] ?? '',
            'rows' => 8,
        ]);
        echo '</td></tr>';

        $this->showFormButtons($options);

        if ((int) $this->getID() > 0) {
            echo "<section class='patchpanel-route-section'>";
            echo '<h2>' . __('Physical route', 'patchpanel') . '</h2>';
            PluginPatchpanelRoute::render((int) $this->getID());
            echo '</section>';
        }
        return true;
    }

    private function showPortWorkflowRow(): void
    {
        global $CFG_GLPI;

        $portId = (int) $this->getID();
        $panelId = (int) ($this->fields['plugin_patchpanel_panels_id'] ?? 0);
        if ($portId <= 0 || $panelId <= 0) {
            return;
        }

        $status = $this->getDisplayStatus();
        $panelUrl = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panel.form.php?id=' .
            $panelId . '&forcetab=PluginPatchpanelPanelPort$1';
        [$previousId, $nextId] = self::getNeighbourPortIds(
            $panelId,
            (int) ($this->fields['number'] ?? 0)
        );

        echo "<tr class='tab_bg_1'><td>" . htmlescape(__('Workflow', 'patchpanel')) . "</td><td colspan='3'>";
        echo "<div class='patchpanel-port-workflow'>";
        echo "<span class='patchpanel-quality-status patchpanel-status-" . htmlescape($status) . "'>";
        echo "<i class='" . htmlescape(self::getStatusIcon($status)) . "'></i> " .
            htmlescape(self::getStatusLabel($status)) . '</span>';
        echo "<a class='btn btn-sm btn-outline-secondary' href='" . htmlescape($panelUrl) . "'>";
        echo "<i class='ti ti-layout-grid'></i> " . htmlescape(__('Visual panel', 'patchpanel')) . '</a>';
        if ($previousId > 0) {
            echo "<a class='btn btn-sm btn-outline-secondary' href='" .
                htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panelport.form.php?id=' . $previousId) .
                "'><i class='ti ti-arrow-left'></i> " . htmlescape(__('Previous port', 'patchpanel')) . '</a>';
        }
        if ($nextId > 0) {
            echo "<a class='btn btn-sm btn-outline-secondary' href='" .
                htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panelport.form.php?id=' . $nextId) .
                "'>" . htmlescape(__('Next port', 'patchpanel')) . " <i class='ti ti-arrow-right'></i></a>";
        }
        echo '</div></td></tr>';
    }

    private static function getNeighbourPortIds(int $panelId, int $number): array
    {
        global $DB;

        $previous = $DB->request([
            'SELECT' => ['id'],
            'FROM' => self::getTable(),
            'WHERE' => [
                'plugin_patchpanel_panels_id' => $panelId,
                'number' => ['<', $number],
            ],
            'ORDER' => ['number DESC'],
            'LIMIT' => 1,
        ])->current();
        $next = $DB->request([
            'SELECT' => ['id'],
            'FROM' => self::getTable(),
            'WHERE' => [
                'plugin_patchpanel_panels_id' => $panelId,
                'number' => ['>', $number],
            ],
            'ORDER' => ['number ASC'],
            'LIMIT' => 1,
        ])->current();
        return [
            (int) ($previous['id'] ?? 0),
            (int) ($next['id'] ?? 0),
        ];
    }
}
