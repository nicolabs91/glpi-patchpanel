<?php

final class PluginPatchpanelRoute extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Physical route', 'patchpanel');
    }

    public static function buildForPort(int $portId): array
    {
        $port = new PluginPatchpanelPanelPort();
        $panel = new PluginPatchpanelPanel();
        $result = [
            'port' => null,
            'panel' => null,
            'rear' => null,
            'front' => null,
            'terminal' => null,
            'upstream' => [],
            'has_broken_reference' => false,
        ];

        if (!$port->getFromDB($portId)) {
            $result['has_broken_reference'] = true;
            return $result;
        }
        $result['port'] = self::stepForItem($port, $port->fields['label'] ?: 'Port ' . $port->fields['number']);

        if ($panel->getFromDB((int) $port->fields['plugin_patchpanel_panels_id'])) {
            $result['panel'] = self::stepForItem($panel);
        } else {
            $result['has_broken_reference'] = true;
        }

        $endpoints = PluginPatchpanelPortEndpoint::getForPort($portId);
        foreach ([PluginPatchpanelPortEndpoint::REAR, PluginPatchpanelPortEndpoint::FRONT] as $side) {
            if (!isset($endpoints[$side])) {
                continue;
            }
            $endpoint = $endpoints[$side];
            $step = self::stepForReference($endpoint['itemtype'], (int) $endpoint['items_id']);
            $step['cable_color'] = $endpoint['cable_color'];
            $step['cable_label'] = $endpoint['cable_label'];
            $step['cables_id'] = (int) $endpoint['cables_id'];
            $result[$side] = $step;
            if ($step['broken']) {
                $result['has_broken_reference'] = true;
            }
        }

        if (($endpoints[PluginPatchpanelPortEndpoint::FRONT]['itemtype'] ?? '') === NetworkPort::class) {
            $frontPortId = (int) $endpoints[PluginPatchpanelPortEndpoint::FRONT]['items_id'];
            $frontPort = new NetworkPort();
            if ($frontPort->getFromDB($frontPortId)) {
                $peerId = (new NetworkPort_NetworkPort())->getOppositeContact($frontPortId);
                if ($peerId) {
                    $peerPort = new NetworkPort();
                    if ($peerPort->getFromDB($peerId)) {
                        $terminal = self::stepForOwner($peerPort);
                        if ($terminal !== null) {
                            $terminal['port'] = self::stepForItem($peerPort);
                            $result['terminal'] = $terminal;
                        }
                    }
                }
                $result['upstream'] = self::findUpstreamPath($frontPort);
            }
        }

        return $result;
    }

    private static function stepForReference(string $itemtype, int $itemsId): array
    {
        if (!class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return self::brokenStep($itemtype, $itemsId);
        }
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId) || !$item->canViewItem()) {
            return self::brokenStep($itemtype, $itemsId);
        }
        return self::stepForItem($item);
    }

    private static function brokenStep(string $itemtype, int $itemsId): array
    {
        return [
            'label' => __('Unavailable or inaccessible object', 'patchpanel'),
            'type' => $itemtype,
            'id' => $itemsId,
            'url' => null,
            'icon' => 'ti ti-link-off',
            'broken' => true,
        ];
    }

    private static function stepForItem(CommonDBTM $item, ?string $label = null): array
    {
        return [
            'label' => $label ?: $item->getName(),
            'type' => $item->getType(),
            'id' => (int) $item->getID(),
            'url' => $item->getLinkURL(),
            'icon' => $item::getIcon(),
            'broken' => false,
        ];
    }

    private static function stepForOwner(NetworkPort $port): ?array
    {
        $type = $port->fields['itemtype'] ?? '';
        $id = (int) ($port->fields['items_id'] ?? 0);
        if (!class_exists($type) || !is_a($type, CommonDBTM::class, true)) {
            return null;
        }
        $owner = new $type();
        return $owner->getFromDB($id) && $owner->canViewItem()
            ? self::stepForItem($owner)
            : null;
    }

    private static function findUpstreamPath(NetworkPort $frontPort): array
    {
        $startType = $frontPort->fields['itemtype'] ?? '';
        $startId = (int) ($frontPort->fields['items_id'] ?? 0);
        if ($startType !== NetworkEquipment::class || $startId <= 0) {
            return [];
        }

        $queue = [[
            'equipment_id' => $startId,
            'steps' => [],
            'visited' => [$startId => true],
        ]];
        $fallback = [];

        while ($queue) {
            $current = array_shift($queue);
            foreach (self::getNetworkEquipmentNeighbours($current['equipment_id']) as $edge) {
                $nextId = (int) $edge['equipment_id'];
                if (isset($current['visited'][$nextId])) {
                    continue;
                }

                $nextSteps = array_merge($current['steps'], [
                    self::stepForReference(NetworkPort::class, (int) $edge['from_port_id']),
                    self::stepForReference(NetworkPort::class, (int) $edge['to_port_id']),
                    self::stepForReference(NetworkEquipment::class, $nextId),
                ]);
                $fallback = $fallback ?: $nextSteps;

                if (self::isRouterOrFirewall($nextId)) {
                    return $nextSteps;
                }
                if (count($current['visited']) >= 8) {
                    continue;
                }
                $visited = $current['visited'];
                $visited[$nextId] = true;
                $queue[] = [
                    'equipment_id' => $nextId,
                    'steps' => $nextSteps,
                    'visited' => $visited,
                ];
            }
        }

        return $fallback;
    }

    private static function getNetworkEquipmentNeighbours(int $equipmentId): array
    {
        global $DB;

        $sql = "SELECT own.id AS from_port_id, peer.id AS to_port_id,
                       peer.items_id AS equipment_id
                FROM glpi_networkports own
                INNER JOIN glpi_networkports_networkports link
                    ON own.id IN (link.networkports_id_1, link.networkports_id_2)
                INNER JOIN glpi_networkports peer
                    ON peer.id = IF(
                        link.networkports_id_1 = own.id,
                        link.networkports_id_2,
                        link.networkports_id_1
                    )
                WHERE own.itemtype = 'NetworkEquipment'
                  AND own.items_id = " . $equipmentId . "
                  AND own.is_deleted = 0
                  AND peer.itemtype = 'NetworkEquipment'
                  AND peer.is_deleted = 0";

        $rows = [];
        $result = $DB->doQuery($sql);
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function isRouterOrFirewall(int $equipmentId): bool
    {
        global $DB;

        $sql = "SELECT LOWER(CONCAT(ne.name, ' ', COALESCE(t.name, ''))) AS descriptor
                FROM glpi_networkequipments ne
                LEFT JOIN glpi_networkequipmenttypes t
                  ON t.id = ne.networkequipmenttypes_id
                WHERE ne.id = " . $equipmentId;
        $result = $DB->doQuery($sql);
        $row = $result ? $result->fetch_assoc() : null;
        return $row !== null
            && preg_match('/router|firewall|gateway/', $row['descriptor']) === 1;
    }

    public static function render(int $portId): void
    {
        $steps = self::getStepsForPort($portId);
        echo "<nav class='patchpanel-route' aria-label='" . htmlescape(__('Physical route', 'patchpanel')) . "'>";
        if (!$steps) {
            echo "<span class='patchpanel-route-empty'><i class='ti ti-circle-dashed'></i> " .
                __('No route registered yet', 'patchpanel') . '</span>';
        }
        foreach ($steps as $index => $step) {
            if ($index > 0) {
                echo "<span class='patchpanel-route-arrow' aria-hidden='true'>→</span>";
            }
            self::renderStep($step);
        }
        echo '</nav>';
    }

    public static function getStepsForPort(int $portId): array
    {
        return self::getSteps(self::buildForPort($portId));
    }

    public static function getSteps(array $route): array
    {
        $steps = [];
        if ($route['terminal']) {
            $steps[] = $route['terminal'];
            if (!empty($route['terminal']['port'])) {
                $steps[] = $route['terminal']['port'];
            }
        }
        if ($route['rear']) {
            $steps[] = $route['rear'];
        }
        if ($route['panel']) {
            $steps[] = $route['panel'];
        }
        if ($route['port']) {
            $steps[] = $route['port'];
        }
        if ($route['front']) {
            $steps[] = $route['front'];
        }

        if ($route['front'] && $route['front']['type'] === NetworkPort::class) {
            $frontPort = new NetworkPort();
            if ($frontPort->getFromDB($route['front']['id'])) {
                $owner = self::stepForOwner($frontPort);
                if ($owner) {
                    $steps[] = $owner;
                }
            }
        }
        $steps = array_merge($steps, $route['upstream']);
        return $steps;
    }

    private static function renderStep(array $step): void
    {
        $class = $step['broken'] ? ' patchpanel-route-step-broken' : '';
        $content = "<i class='" . htmlescape($step['icon'] ?: 'ti ti-link') . "'></i> " .
            htmlescape($step['label'] ?: sprintf('#%d', $step['id']));
        if (!empty($step['url'])) {
            echo "<a class='patchpanel-route-step$class' href='" . htmlescape($step['url']) . "'>$content</a>";
        } else {
            echo "<span class='patchpanel-route-step$class'>$content</span>";
        }
    }

    public static function renderForEndpoint(CommonDBTM $item): void
    {
        global $DB;

        $ids = [];
        if ($item instanceof NetworkPort || $item instanceof \Glpi\Socket) {
            foreach ($DB->request([
                'SELECT' => ['plugin_patchpanel_panelports_id'],
                'FROM' => PluginPatchpanelPortEndpoint::getTable(),
                'WHERE' => [
                    'itemtype' => $item->getType(),
                    'items_id' => $item->getID(),
                ],
            ]) as $row) {
                $ids[] = (int) $row['plugin_patchpanel_panelports_id'];
            }
        } else {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => NetworkPort::getTable(),
                'WHERE' => [
                    'itemtype' => $item->getType(),
                    'items_id' => $item->getID(),
                    'is_deleted' => 0,
                ],
            ]) as $port) {
                foreach ($DB->request([
                    'SELECT' => ['plugin_patchpanel_panelports_id'],
                    'FROM' => PluginPatchpanelPortEndpoint::getTable(),
                    'WHERE' => [
                        'itemtype' => NetworkPort::class,
                        'items_id' => $port['id'],
                    ],
                ]) as $row) {
                    $ids[] = (int) $row['plugin_patchpanel_panelports_id'];
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if (!$ids) {
            echo "<div class='alert alert-info'>" .
                __('This object is not directly registered on a patch panel route.', 'patchpanel') .
                '</div>';
            return;
        }
        foreach ($ids as $portId) {
            echo "<div class='card mb-3'><div class='card-body'>";
            self::render($portId);
            echo '</div></div>';
        }
    }
}
