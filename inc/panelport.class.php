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

    public static function getMediaOptions(): array
    {
        return [
            'copper' => __('Copper', 'patchpanel'),
            'fiber-sm' => __('Single-mode fiber', 'patchpanel'),
            'fiber-mm' => __('Multimode fiber', 'patchpanel'),
            'other' => __('Other'),
        ];
    }

    public static function normalizeMedia(string $media): string
    {
        return array_key_exists($media, self::getMediaOptions()) ? $media : 'other';
    }

    public static function synchronizeForPanel(PluginPatchpanelPanel $panel): void
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
                    'media' => $panel->fields['media'],
                    'date_mod' => $now,
                ];
                if (isset($existing[$number])) {
                    $DB->update(self::getTable(), $layout, ['id' => $existing[$number]]);
                    continue;
                }
                $DB->insert(self::getTable(), $layout + [
                    'plugin_patchpanel_panels_id' => $panelId,
                    'number' => $number,
                    'label' => sprintf(__('Patch port %02d', 'patchpanel'), $number),
                    'operational_state' => 'active',
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
                $DB->delete(
                    self::getTable(),
                    [
                        'id' => $extraIds,
                        'NOT' => [
                            'id' => new \Glpi\DBAL\QuerySubQuery([
                                'SELECT' => 'plugin_patchpanel_panelports_id',
                                'FROM' => PluginPatchpanelPortEndpoint::getTable(),
                            ]),
                        ],
                    ]
                );
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

        $legacy = PluginPatchpanelMigration::getLegacySummary();
        if ($legacy['available']) {
            echo "<div class='alert alert-info d-flex align-items-center gap-2'>";
            echo "<i class='ti ti-database'></i><span>";
            echo sprintf(
                __('Legacy source detected: %1$d panels and %2$d ports. It has not been changed or imported.', 'patchpanel'),
                $legacy['panels'],
                $legacy['ports']
            );
            echo '</span></div>';
        }

        echo "<div class='patchpanel-legend' aria-label='" . htmlescape(__('Status legend', 'patchpanel')) . "'>";
        foreach ([
            'free' => __('Free', 'patchpanel'),
            'partial' => __('Incomplete', 'patchpanel'),
            'connected' => __('Connected', 'patchpanel'),
            'warning' => __('Broken reference', 'patchpanel'),
            'disabled' => __('Out of service', 'patchpanel'),
        ] as $status => $label) {
            echo "<span class='patchpanel-legend-item patchpanel-status-$status'>";
            echo "<i class='" . self::getStatusIcon($status) . "'></i> " . htmlescape($label);
            echo '</span>';
        }
        echo '</div>';

        $columns = (int) ceil(
            (int) $panel->fields['port_count'] / max(1, (int) $panel->fields['rows'])
        );
        $columns = min(24, max(6, $columns));
        echo "<div class='patchpanel-grid' style='--patchpanel-columns:$columns'>";
        foreach ($ports as $data) {
            $port = new self();
            $port->fields = $data;
            $status = $port->getDisplayStatus();
            $url = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panelport.form.php?id=' . (int) $data['id'];
            $route = PluginPatchpanelRoute::buildForPort((int) $data['id']);
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

    public function getDisplayStatus(): string
    {
        if (($this->fields['operational_state'] ?? '') === 'disabled') {
            return 'disabled';
        }
        $route = PluginPatchpanelRoute::buildForPort((int) $this->fields['id']);
        if ($route['has_broken_reference']) {
            return 'warning';
        }
        $count = (int) isset($route['rear']) + (int) isset($route['front']);
        return match ($count) {
            0 => 'free',
            1 => 'partial',
            default => 'connected',
        };
    }

    public static function getStatusIcon(string $status): string
    {
        return match ($status) {
            'connected' => 'ti ti-circle-check',
            'partial' => 'ti ti-alert-triangle',
            'warning' => 'ti ti-link-off',
            'disabled' => 'ti ti-ban',
            default => 'ti ti-circle-dashed',
        };
    }

    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'connected' => __('Connected', 'patchpanel'),
            'partial' => __('Incomplete', 'patchpanel'),
            'warning' => __('Broken reference', 'patchpanel'),
            'disabled' => __('Out of service', 'patchpanel'),
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
        Dropdown::showFromArray('operational_state', [
            'active' => __('Active'),
            'reserved' => __('Reserved', 'patchpanel'),
            'fault' => __('Fault', 'patchpanel'),
            'disabled' => __('Out of service', 'patchpanel'),
        ], ['value' => $this->fields['operational_state'] ?? 'active']);
        echo '</td><td>' . __('Media', 'patchpanel') . '</td><td>';
        Dropdown::showFromArray('media', self::getMediaOptions(), [
            'value' => $this->fields['media'] ?? 'copper',
        ]);
        echo '</td></tr>';

        PluginPatchpanelPortEndpoint::showEndpointFields((int) $this->getID());
        echo "<tr class='tab_bg_1'><td>" . _n('Comment', 'Comments', 1) . "</td><td colspan='3'>";
        echo Html::textarea(['name' => 'comment', 'value' => $this->fields['comment'] ?? '']);
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
}
