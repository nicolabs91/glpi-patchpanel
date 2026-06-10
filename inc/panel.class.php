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
        $input['port_count'] = max(1, min(512, (int) ($input['port_count'] ?? 24)));
        $input['rows'] = max(1, min(8, (int) ($input['rows'] ?? 1)));
        $input['media'] = PluginPatchpanelPanelPort::normalizeMedia($input['media'] ?? 'copper');
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input): array|false
    {
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
        PluginPatchpanelPanelPort::synchronizeForPanel($this);
    }

    public function post_updateItem($history = true): void
    {
        parent::post_updateItem($history);
        PluginPatchpanelPanelPort::synchronizeForPanel($this);
    }

    public function cleanDBonPurge()
    {
        global $DB;

        $portIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => PluginPatchpanelPanelPort::getTable(),
            'WHERE' => ['plugin_patchpanel_panels_id' => $this->getID()],
        ]) as $row) {
            $portIds[] = (int) $row['id'];
        }

        if ($portIds) {
            $DB->delete(PluginPatchpanelPortEndpoint::getTable(), [
                'plugin_patchpanel_panelports_id' => $portIds,
            ]);
            $DB->delete(PluginPatchpanelPanelPort::getTable(), ['id' => $portIds]);
        }
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

        echo "<tr class='tab_bg_1'><td>" . __('Model') . "</td><td>";
        PluginPatchpanelPanelModel::dropdown([
            'name' => 'plugin_patchpanel_panelmodels_id',
            'value' => $this->fields['plugin_patchpanel_panelmodels_id'] ?? 0,
        ]);
        echo '</td><td>' . __('Number of ports', 'patchpanel') . '</td><td>';
        echo Html::input('port_count', [
            'type' => 'number',
            'value' => $this->fields['port_count'] ?? 24,
            'min' => 1,
            'max' => 512,
        ]);
        echo '</td></tr>';

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

        echo "<tr class='tab_bg_1'><td>" . __('Serial number') . "</td><td>";
        echo Html::input('serial', ['value' => $this->fields['serial'] ?? '']);
        echo '</td><td>' . __('Inventory number') . '</td><td>';
        echo Html::input('otherserial', ['value' => $this->fields['otherserial'] ?? '']);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . _n('Comment', 'Comments', 1) . "</td><td colspan='3'>";
        echo Html::textarea(['name' => 'comment', 'value' => $this->fields['comment'] ?? '']);
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
