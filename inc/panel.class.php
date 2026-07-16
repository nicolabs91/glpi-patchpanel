<?php

class PluginPatchpanelPanel extends CommonDBTM
{
    public $dohistory = true;
    public static $rightname = 'networking';

    public static function getTypeName($nb = 0): string
    {
        return _n('Patch panel', 'Patch panels', $nb, 'patchpanel');
    }

    public static function getIcon(): string
    {
        return 'ti ti-layout-grid';
    }

    public static function getMenuName(): string
    {
        return self::getTypeName(Session::getPluralNumber());
    }

    public static function getMenuContent(): array|false
    {
        if (!self::canView()) {
            return false;
        }

        $list = '/plugins/patchpanel/front/panel.php';
        $menu = [
            'title' => self::getMenuName(),
            'page' => $list,
            'icon' => self::getIcon(),
            'links' => ['search' => $list],
            'options' => [
                'patchpanel_routes' => [
                    'title' => __('Route explorer', 'patchpanel'),
                    'page' => '/plugins/patchpanel/front/routes.php',
                    'icon' => 'ti ti-route',
                    'links' => [
                        'search' => '/plugins/patchpanel/front/routes.php',
                    ],
                ],
            ],
        ];
        if (self::canCreate()) {
            $menu['links']['add'] = '/plugins/patchpanel/front/panel.form.php?id=-1';
        }
        return $menu;
    }

    public function defineTabs($options = []): array
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab('PluginPatchpanelPanelPort', $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);
        return $tabs;
    }

    public function prepareInputForAdd($input): array|false
    {
        $input = $this->applySelectedModel($input, true);
        $input['port_count'] = max(1, min(512, (int) ($input['port_count'] ?? 24)));
        $input['rows'] = max(1, min(8, (int) ($input['rows'] ?? 1)));
        $input['media'] = PluginPatchpanelPanelPort::normalizeMedia($input['media'] ?? 'copper');
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input): array|false
    {
        $input = $this->applySelectedModel($input, false);
        if (isset($input['port_count'])) {
            $input['port_count'] = max(1, min(512, (int) $input['port_count']));
            if (!$this->canReduceTo((int) $input['port_count'])) {
                Session::addMessageAfterRedirect(
                    __('Disconnect ports outside the new range before reducing the panel size.', 'patchpanel'),
                    false,
                    ERROR
                );
                return false;
            }
        }
        if (isset($input['rows'])) {
            $input['rows'] = max(1, min(8, (int) $input['rows']));
        }
        if (isset($input['media'])) {
            $input['media'] = PluginPatchpanelPanelPort::normalizeMedia($input['media']);
        }
        return parent::prepareInputForUpdate($input);
    }

    private function applySelectedModel(array $input, bool $isNew): array
    {
        $modelId = (int) ($input['plugin_patchpanel_panelmodels_id'] ?? 0);
        $apply = $isNew || !empty($input['apply_model']);
        unset($input['apply_model']);

        if (!$apply || $modelId <= 0) {
            return $input;
        }

        $definition = PluginPatchpanelPanelModel::getDefinition($modelId);
        if ($definition === null) {
            Session::addMessageAfterRedirect(
                __('The selected patch panel model does not exist.', 'patchpanel'),
                false,
                ERROR
            );
            return $input;
        }

        return array_merge($input, $definition);
    }

    private function canReduceTo(int $portCount): bool
    {
        global $DB;

        if ((int) $this->getID() <= 0) {
            return true;
        }

        $sql = 'SELECT MAX(p.number) AS highest_connected
                FROM ' . PluginPatchpanelPanelPort::getTable() . ' p
                INNER JOIN ' . PluginPatchpanelPortEndpoint::getTable() . ' e
                    ON e.plugin_patchpanel_panelports_id = p.id
                WHERE p.plugin_patchpanel_panels_id = ' . (int) $this->getID();
        $result = $DB->doQuery($sql);
        $row = $result ? $result->fetch_assoc() : null;
        return (int) ($row['highest_connected'] ?? 0) <= $portCount;
    }

    public function post_addItem(): void
    {
        parent::post_addItem();
        PluginPatchpanelPanelPort::synchronizeForPanel($this, true);
    }

    public function post_updateItem($history = true): void
    {
        parent::post_updateItem($history);
        PluginPatchpanelPanelPort::synchronizeForPanel(
            $this,
            in_array('media', $this->updates, true)
        );
    }

    public function cleanDBonPurge()
    {
        global $DB;

        $DB->delete('glpi_plugin_patchpanel_audits', [
            'plugin_patchpanel_panels_id' => $this->getID(),
        ]);

        $portIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => PluginPatchpanelPanelPort::getTable(),
            'WHERE' => ['plugin_patchpanel_panels_id' => $this->getID()],
        ]) as $row) {
            $portIds[] = (int) $row['id'];
        }

        if ($portIds) {
            $batchUuids = [];
            $changeIds = [];
            foreach ($DB->request([
                'SELECT' => [
                    'glpi_plugin_patchpanel_importchanges.id',
                    'glpi_plugin_patchpanel_importchanges.batch_uuid',
                ],
                'FROM' => 'glpi_plugin_patchpanel_importchanges',
                'INNER JOIN' => [
                    'glpi_plugin_patchpanel_importbatches' => [
                        'FKEY' => [
                            'glpi_plugin_patchpanel_importchanges' => 'batch_uuid',
                            'glpi_plugin_patchpanel_importbatches' => 'batch_uuid',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_plugin_patchpanel_importchanges.plugin_patchpanel_panelports_id' => $portIds,
                    'glpi_plugin_patchpanel_importbatches.status' => 'rolled_back',
                ],
            ]) as $change) {
                $changeIds[] = (int) $change['id'];
                $batchUuids[] = (string) $change['batch_uuid'];
            }
            if ($changeIds) {
                $DB->delete('glpi_plugin_patchpanel_importchanges', ['id' => $changeIds]);
                foreach (array_unique($batchUuids) as $batchUuid) {
                    if (countElementsInTable(
                        'glpi_plugin_patchpanel_importchanges',
                        ['batch_uuid' => $batchUuid]
                    ) === 0) {
                        $DB->delete(
                            'glpi_plugin_patchpanel_importbatches',
                            ['batch_uuid' => $batchUuid]
                        );
                    }
                }
            }
            $DB->delete(PluginPatchpanelPortEndpoint::getTable(), [
                'plugin_patchpanel_panelports_id' => $portIds,
            ]);
            PluginPatchpanelPortEndpoint::cleanupPanelNetworkPortsForPanelPorts($portIds);
            $DB->delete(PluginPatchpanelPanelPort::getTable(), ['id' => $portIds]);
        }
    }

    public function pre_deleteItem()
    {
        global $DB;

        if (empty($this->input['_purge'])) {
            return true;
        }

        $active = $DB->request([
            'SELECT' => ['glpi_plugin_patchpanel_importchanges.id'],
            'FROM' => 'glpi_plugin_patchpanel_importchanges',
            'INNER JOIN' => [
                PluginPatchpanelPanelPort::getTable() => [
                    'FKEY' => [
                        'glpi_plugin_patchpanel_importchanges' => 'plugin_patchpanel_panelports_id',
                        PluginPatchpanelPanelPort::getTable() => 'id',
                    ],
                ],
                'glpi_plugin_patchpanel_importbatches' => [
                    'FKEY' => [
                        'glpi_plugin_patchpanel_importchanges' => 'batch_uuid',
                        'glpi_plugin_patchpanel_importbatches' => 'batch_uuid',
                    ],
                ],
            ],
            'WHERE' => [
                PluginPatchpanelPanelPort::getTable() . '.plugin_patchpanel_panels_id' => $this->getID(),
                'glpi_plugin_patchpanel_importbatches.status' => 'applied',
            ],
            'LIMIT' => 1,
        ])->current();
        if ($active) {
            Session::addMessageAfterRedirect(
                __('Rollback active CSV import batches before purging this panel.', 'patchpanel'),
                false,
                ERROR
            );
            return false;
        }
        return true;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'><td>" . __('Name') . "</td><td>";
        echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40]);
        echo '</td><td>' . __('Location') . '</td><td>';
        Location::dropdown([
            'name' => 'locations_id',
            'value' => $this->fields['locations_id'] ?? 0,
            'entity' => $this->fields['entities_id'] ?? Session::getActiveEntity(),
        ]);
        echo '</td></tr>';

        $hasModel = (int) ($this->fields['plugin_patchpanel_panelmodels_id'] ?? 0) > 0;

        echo "<tr class='tab_bg_1'><td>" . __('Model') . "</td><td>";
        PluginPatchpanelPanelModel::dropdown([
            'name' => 'plugin_patchpanel_panelmodels_id',
            'value' => $this->fields['plugin_patchpanel_panelmodels_id'] ?? 0,
        ]);
        if ($hasModel) {
            echo '</td><td colspan="2">';
            echo Html::hidden('port_count', ['value' => $this->fields['port_count'] ?? 24]);
        } else {
            echo '</td><td>' . __('Number of ports', 'patchpanel') . '</td><td>';
            echo Html::input('port_count', [
                'type' => 'number',
                'value' => $this->fields['port_count'] ?? 24,
                'min' => 1,
                'max' => 512,
            ]);
        }
        echo '</td></tr>';

        if (!$this->isNewID($ID)) {
            echo Html::hidden('apply_model', ['value' => 1]);
        } else {
            echo "<tr class='tab_bg_1'><td colspan='4'><div class='alert alert-info mb-0'>";
            echo htmlescape(
                __('For a new panel, selecting a model automatically sets port count, rows and media. You can leave the model empty for a custom layout.', 'patchpanel')
            );
            echo '</div></td></tr>';
        }

        echo "<tr class='tab_bg_1'><td>" . __('Rows', 'patchpanel') . "</td><td>";
        Dropdown::showNumber('rows', [
            'value' => $this->fields['rows'] ?? 1,
            'min' => 1,
            'max' => 8,
        ]);
        echo '</td><td>' . __('Media', 'patchpanel') . '</td><td>';
        Dropdown::showFromArray('media', PluginPatchpanelPanelPort::getMediaOptions(), [
            'value' => $this->fields['media'] ?? 'copper',
        ]);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __('Inventory number') . '</td><td colspan="3">';
        echo Html::input('otherserial', ['value' => $this->fields['otherserial'] ?? '']);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . _n('Comment', 'Comments', 1) . "</td><td colspan='3'>";
        Html::textarea([
            'name' => 'comment',
            'value' => $this->fields['comment'] ?? '',
            'rows' => 8,
        ]);
        echo '</td></tr>';

        $this->showFormButtons($options);
        return true;
    }

    public static function getSearchOptionsNew(): array
    {
        $tab = parent::getSearchOptionsNew();
        $tab[] = [
            'id' => 10,
            'table' => self::getTable(),
            'field' => 'port_count',
            'name' => __('Number of ports', 'patchpanel'),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id' => 11,
            'table' => self::getTable(),
            'field' => 'media',
            'name' => __('Media', 'patchpanel'),
            'datatype' => 'specific',
        ];
        return $tab;
    }
}
