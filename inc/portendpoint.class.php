<?php

use Glpi\DBAL\QuerySubQuery;

class PluginPatchpanelPortEndpoint extends CommonDBTM
{
    public static $rightname = 'networking';
    public $dohistory = true;

    public const REAR = 'rear';
    public const FRONT = 'front';

    public static function getTypeName($nb = 0): string
    {
        return _n('Panel endpoint', 'Panel endpoints', $nb, 'patchpanel');
    }

    public static function getForPort(int $portId): array
    {
        global $DB;

        $endpoints = [];
        foreach ($DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['plugin_patchpanel_panelports_id' => $portId],
        ]) as $row) {
            $endpoints[$row['side']] = $row;
        }
        return $endpoints;
    }

    public static function showEndpointFields(int $portId): void
    {
        $endpoints = self::getForPort($portId);
        $rear = $endpoints[self::REAR] ?? [];
        $front = $endpoints[self::FRONT] ?? [];

        echo "<tr class='tab_bg_2'><th colspan='4'>" .
            __('Rear side: permanent cabling', 'patchpanel') . '</th></tr>';
        echo "<tr class='tab_bg_1'><td>" . __('Wall outlet / connection point', 'patchpanel') . "</td><td>";
        Dropdown::show('Glpi\\Socket', [
            'name' => 'rear_items_id',
            'value' => $rear['items_id'] ?? 0,
            'condition' => self::getAvailableCondition('Glpi\\Socket', (int) ($rear['items_id'] ?? 0)),
        ]);
        echo '</td><td>' . __('Cable color', 'patchpanel') . '</td><td>';
        self::showColorDropdown('rear_cable_color', $rear['cable_color'] ?? '');
        echo '</td></tr>';

        echo "<tr class='tab_bg_2'><th colspan='4'>" .
            __('Front side: patch cable', 'patchpanel') . '</th></tr>';
        echo "<tr class='tab_bg_1'><td>" . __('Switch / router port', 'patchpanel') . "</td><td>";
        Dropdown::show(NetworkPort::class, [
            'name' => 'front_items_id',
            'value' => $front['items_id'] ?? 0,
            'condition' => self::getAvailableCondition(NetworkPort::class, (int) ($front['items_id'] ?? 0)),
        ]);
        echo '</td><td>' . __('Patch cable color', 'patchpanel') . '</td><td>';
        self::showColorDropdown('front_cable_color', $front['cable_color'] ?? '');
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'><td>" . __('Cable ID', 'patchpanel') . "</td><td>";
        echo Html::input('front_cable_label', ['value' => $front['cable_label'] ?? '']);
        echo '</td><td>' . __('GLPI cable', 'patchpanel') . '</td><td>';
        Cable::dropdown([
            'name' => 'front_cables_id',
            'value' => $front['cables_id'] ?? 0,
        ]);
        echo '</td></tr>';
    }

    private static function showColorDropdown(string $name, string $value): void
    {
        Dropdown::showFromArray($name, [
            '' => Dropdown::EMPTY_VALUE,
            '#6c757d' => __('Gray'),
            '#212529' => __('Black'),
            '#ffffff' => __('White'),
            '#dc3545' => __('Red'),
            '#fd7e14' => __('Orange'),
            '#ffc107' => __('Yellow'),
            '#198754' => __('Green'),
            '#0d6efd' => __('Blue'),
            '#6f42c1' => __('Purple'),
            '#d63384' => __('Pink'),
        ], ['value' => $value]);
    }

    private static function getAvailableCondition(string $itemtype, int $currentId): array
    {
        $condition = [
            'NOT' => [
                'id' => new QuerySubQuery([
                    'SELECT' => 'items_id',
                    'FROM' => self::getTable(),
                    'WHERE' => ['itemtype' => $itemtype],
                ]),
            ],
        ];
        if ($currentId > 0) {
            return ['OR' => ['id' => $currentId, $condition]];
        }
        return $condition;
    }

    public static function saveForPort(int $portId, array $input): bool
    {
        global $DB;

        $port = new PluginPatchpanelPanelPort();
        $port->check($portId, UPDATE);

        $desired = [
            self::REAR => [
                'itemtype' => 'Glpi\\Socket',
                'items_id' => (int) ($input['rear_items_id'] ?? 0),
                'cable_color' => self::normalizeColor($input['rear_cable_color'] ?? ''),
                'cables_id' => 0,
                'cable_label' => null,
            ],
            self::FRONT => [
                'itemtype' => NetworkPort::class,
                'items_id' => (int) ($input['front_items_id'] ?? 0),
                'cable_color' => self::normalizeColor($input['front_cable_color'] ?? ''),
                'cables_id' => max(0, (int) ($input['front_cables_id'] ?? 0)),
                'cable_label' => trim((string) ($input['front_cable_label'] ?? '')),
            ],
        ];

        $DB->beginTransaction();
        try {
            foreach ($desired as $side => $endpoint) {
                self::saveSide($portId, $side, $endpoint);
            }
            $DB->commit();
            return true;
        } catch (Throwable $e) {
            $DB->rollBack();
            Toolbox::logInFile(
                'php-errors',
                'PatchPanel endpoint update failed: ' . $e->getMessage() . "\n"
            );
            Session::addMessageAfterRedirect(
                __('The connection could not be saved. The selected endpoint may already be in use.', 'patchpanel'),
                false,
                ERROR
            );
            return false;
        }
    }

    private static function saveSide(int $portId, string $side, array $endpoint): void
    {
        global $DB;

        $table = self::getTable();
        $existing = $DB->request([
            'FROM' => $table,
            'WHERE' => [
                'plugin_patchpanel_panelports_id' => $portId,
                'side' => $side,
            ],
            'LIMIT' => 1,
        ])->current();

        if ($endpoint['items_id'] <= 0) {
            if ($existing) {
                $DB->delete($table, ['id' => $existing['id']]);
            }
            return;
        }

        if (!self::isValidEndpoint($endpoint['itemtype'], $endpoint['items_id'])) {
            throw new InvalidArgumentException('Invalid endpoint');
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $values = $endpoint + [
            'plugin_patchpanel_panelports_id' => $portId,
            'side' => $side,
            'date_mod' => $now,
        ];
        if ($existing) {
            $DB->update($table, $values, ['id' => $existing['id']]);
            return;
        }
        $values['date_creation'] = $now;
        $DB->insert($table, $values);
    }

    private static function isValidEndpoint(string $itemtype, int $itemsId): bool
    {
        if (!in_array($itemtype, ['Glpi\\Socket', NetworkPort::class], true)) {
            return false;
        }
        $item = new $itemtype();
        return $item->getFromDB($itemsId);
    }

    private static function normalizeColor(string $color): ?string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : null;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof CommonDBTM) {
            return '';
        }
        return self::createTabEntry(__('Patch panel routes', 'patchpanel'));
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        PluginPatchpanelRoute::renderForEndpoint($item);
        return true;
    }
}
