<?php

final class PluginPatchpanelRoute extends CommonGLPI
{
    private static array $referenceStepCache = [];
    private static array $ownerStepCache = [];
    private static array $networkEquipmentNeighboursCache = [];
    private static array $routerFirewallCache = [];

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

        $result['terminal'] = self::terminalFromRearEndpoint($endpoints);

        if (($endpoints[PluginPatchpanelPortEndpoint::FRONT]['itemtype'] ?? '') === NetworkPort::class) {
            $frontPortId = (int) $endpoints[PluginPatchpanelPortEndpoint::FRONT]['items_id'];
            $frontPort = new NetworkPort();
            if ($frontPort->getFromDB($frontPortId)) {
                if ($result['terminal'] === null) {
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
                }
                $result['upstream'] = self::findUpstreamPath($frontPort);
            }
        }

        return $result;
    }

    private static function terminalFromRearEndpoint(array $endpoints): ?array
    {
        $rear = $endpoints[PluginPatchpanelPortEndpoint::REAR] ?? null;
        if (($rear['itemtype'] ?? '') !== \Glpi\Socket::class) {
            return null;
        }

        $socket = new \Glpi\Socket();
        if (!$socket->getFromDB((int) ($rear['items_id'] ?? 0))) {
            return null;
        }

        $networkPortId = (int) ($socket->fields['networkports_id'] ?? 0);
        if ($networkPortId > 0) {
            $networkPort = new NetworkPort();
            if (
                $networkPort->getFromDB($networkPortId)
                && (int) ($networkPort->fields['is_deleted'] ?? 0) === 0
            ) {
                $terminal = self::stepForOwner($networkPort);
                if ($terminal !== null) {
                    $terminal['port'] = self::stepForItem($networkPort);
                    return $terminal;
                }
            }
        }
        return null;
    }

    private static function stepForReference(string $itemtype, int $itemsId): array
    {
        $cacheKey = $itemtype . ':' . $itemsId;
        if (isset(self::$referenceStepCache[$cacheKey])) {
            return self::$referenceStepCache[$cacheKey];
        }
        if (!class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return self::$referenceStepCache[$cacheKey] = self::brokenStep($itemtype, $itemsId);
        }
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId) || !$item->canViewItem()) {
            return self::$referenceStepCache[$cacheKey] = self::brokenStep($itemtype, $itemsId);
        }
        if ($item instanceof NetworkPort && (int) ($item->fields['is_deleted'] ?? 0) !== 0) {
            return self::$referenceStepCache[$cacheKey] = self::brokenStep($itemtype, $itemsId);
        }
        return self::$referenceStepCache[$cacheKey] = self::stepForItem($item);
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
        $cacheKey = $type . ':' . $id;
        if (array_key_exists($cacheKey, self::$ownerStepCache)) {
            return self::$ownerStepCache[$cacheKey];
        }
        if (!class_exists($type) || !is_a($type, CommonDBTM::class, true)) {
            return self::$ownerStepCache[$cacheKey] = null;
        }
        $owner = new $type();
        return self::$ownerStepCache[$cacheKey] = $owner->getFromDB($id) && $owner->canViewItem()
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

        if (isset(self::$networkEquipmentNeighboursCache[$equipmentId])) {
            return self::$networkEquipmentNeighboursCache[$equipmentId];
        }

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
        return self::$networkEquipmentNeighboursCache[$equipmentId] = $rows;
    }

    private static function isRouterOrFirewall(int $equipmentId): bool
    {
        global $DB;

        if (isset(self::$routerFirewallCache[$equipmentId])) {
            return self::$routerFirewallCache[$equipmentId];
        }

        $sql = "SELECT LOWER(CONCAT(ne.name, ' ', COALESCE(t.name, ''))) AS descriptor
                FROM glpi_networkequipments ne
                LEFT JOIN glpi_networkequipmenttypes t
                  ON t.id = ne.networkequipmenttypes_id
                WHERE ne.id = " . $equipmentId;
        $result = $DB->doQuery($sql);
        $row = $result ? $result->fetch_assoc() : null;
        return self::$routerFirewallCache[$equipmentId] = $row !== null
            && preg_match('/router|firewall|gateway/', $row['descriptor']) === 1;
    }

    public static function render(int $portId): void
    {
        self::renderSteps(self::getStepsForPort($portId));
    }

    public static function renderSteps(array $steps): void
    {
        self::renderLegend();
        echo "<nav class='patchpanel-route' aria-label='" . htmlescape(__('Physical route', 'patchpanel')) . "'>";
        if (!$steps) {
            echo "<span class='patchpanel-route-empty'><i class='ti ti-circle-dashed'></i> " .
                __('No route registered yet', 'patchpanel') . '</span>';
        }
        $primarySteps = array_values(array_filter(
            $steps,
            static fn(array $step) => empty($step['is_upstream'])
        ));
        $upstreamSteps = array_values(array_filter(
            $steps,
            static fn(array $step) => !empty($step['is_upstream'])
        ));
        foreach ($primarySteps as $index => $step) {
            if ($index > 0) {
                echo "<span class='patchpanel-route-arrow' aria-hidden='true'>→</span>";
            }
            self::renderStep($step);
        }
        if ($upstreamSteps) {
            if ($primarySteps) {
                echo "<span class='patchpanel-route-arrow' aria-hidden='true'>→</span>";
            }
            echo "<details class='patchpanel-route-more'>";
            echo "<summary class='patchpanel-route-more-toggle' aria-label='" .
                htmlescape(__('Show upstream core route', 'patchpanel')) . "'>...</summary>";
            echo "<span class='patchpanel-route-more-steps'>";
            foreach ($upstreamSteps as $index => $step) {
                if ($index > 0) {
                    echo "<span class='patchpanel-route-arrow' aria-hidden='true'>→</span>";
                }
                self::renderStep($step);
            }
            echo '</span></details>';
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
            $steps[] = self::withZone($route['terminal'], 'endpoint');
            if (!empty($route['terminal']['port'])) {
                $steps[] = self::withZone($route['terminal']['port'], 'endpoint');
            }
        }
        if ($route['rear']) {
            $steps[] = self::withZone($route['rear'], 'connection');
        }
        if ($route['panel']) {
            $steps[] = self::withZone($route['panel'], 'panel');
        }
        if ($route['port']) {
            $steps[] = self::withZone($route['port'], 'panel');
        }
        if ($route['front']) {
            $steps[] = self::withZone($route['front'], 'access');
        }

        if ($route['front'] && $route['front']['type'] === NetworkPort::class) {
            $frontPort = new NetworkPort();
            if ($frontPort->getFromDB($route['front']['id'])) {
                $owner = self::stepForOwner($frontPort);
                if ($owner) {
                    $zone = $owner['type'] === NetworkEquipment::class
                        && self::isRouterOrFirewall((int) $owner['id'])
                        ? 'gateway'
                        : 'access';
                    $steps[] = self::withZone($owner, $zone);
                }
            }
        }

        $upstream = array_values($route['upstream']);
        $last = $upstream ? $upstream[count($upstream) - 1] : null;
        $endsAtGateway = $last
            && ($last['type'] ?? '') === NetworkEquipment::class
            && self::isRouterOrFirewall((int) ($last['id'] ?? 0));
        foreach (array_chunk($upstream, 3) as $index => $edge) {
            $isLastEdge = (($index + 1) * 3) >= count($upstream);
            $fromZone = $index === 0 ? 'access' : 'core';
            $toZone = $isLastEdge && $endsAtGateway ? 'gateway' : 'core';
            if (isset($edge[0])) {
                $steps[] = self::withZone($edge[0], $fromZone, true);
            }
            if (isset($edge[1])) {
                $steps[] = self::withZone($edge[1], $toZone, true);
            }
            if (isset($edge[2])) {
                $steps[] = self::withZone($edge[2], $toZone, true);
            }
        }
        return $steps;
    }

    private static function withZone(array $step, string $zone, bool $isUpstream = false): array
    {
        $step['zone'] = $zone;
        if ($isUpstream) {
            $step['is_upstream'] = true;
        }
        return $step;
    }

    private static function routeZoneStyles(): array
    {
        return [
            'endpoint' => ['#ede9fe', '#7c3aed', '#4c1d95'],
            'connection' => ['#ffffff', '#1f2937', '#1f2937'],
            'panel' => ['#ffedd5', '#b45309', '#7c2d12'],
            'access' => ['#dbeafe', '#1d4ed8', '#1e3a8a'],
            'core' => ['#e0e7ff', '#4338ca', '#312e81'],
            'gateway' => ['#fee2e2', '#b91c1c', '#7f1d1d'],
        ];
    }

    private static function routeZoneInlineStyle(string $zone, bool $isStep = false): string
    {
        $styles = self::routeZoneStyles();
        if (!isset($styles[$zone])) {
            return '';
        }

        [$background, $border, $text] = $styles[$zone];
        $style = sprintf(
            'background-color:%s;border:2px solid %s;color:%s',
            $background,
            $border,
            $text
        );

        if ($isStep) {
            $style .= ';border-left-width:.35rem';
        }

        return $style;
    }

    private static function renderLegend(): void
    {
        echo "<div class='patchpanel-route-legend' role='list' aria-label='" .
            htmlescape(__('Route color legend', 'patchpanel')) . "'>";
        foreach ([
            'endpoint' => ['ti ti-device-desktop', __('End device', 'patchpanel')],
            'connection' => ['ti ti-plug-connected', __('Connection point', 'patchpanel')],
            'panel' => ['ti ti-layout-grid', __('Patch panel', 'patchpanel')],
            'access' => ['ti ti-network', __('Access switch', 'patchpanel')],
            'core' => ['ti ti-server-2', __('Core network', 'patchpanel')],
            'gateway' => ['ti ti-shield-lock', __('Firewall / router', 'patchpanel')],
        ] as $zone => [$icon, $label]) {
            $style = self::routeZoneInlineStyle($zone);
            echo "<span role='listitem' class='patchpanel-route-legend-item patchpanel-route-zone-" .
                htmlescape($zone) . "' style='" . htmlescape($style) . "'><i class='" . htmlescape($icon) .
                "'></i> " . htmlescape($label) . '</span>';
        }
        echo '</div>';
    }

    private static function renderStep(array $step): void
    {
        $class = $step['broken'] ? ' patchpanel-route-step-broken' : '';
        $zone = $step['zone'] ?? '';
        if (in_array($zone, ['endpoint', 'connection', 'panel', 'access', 'core', 'gateway'], true)) {
            $class .= ' patchpanel-route-zone-' . $zone;
        }
        $style = self::routeZoneInlineStyle($zone, true);
        $styleAttribute = $style !== '' ? " style='" . htmlescape($style) . "'" : '';
        $content = "<i class='" . htmlescape($step['icon'] ?: 'ti ti-link') . "'></i> " .
            htmlescape($step['label'] ?: sprintf('#%d', $step['id']));
        if (!empty($step['url'])) {
            echo "<a class='patchpanel-route-step$class' data-route-zone='" .
                htmlescape($zone) . "' href='" . htmlescape($step['url']) . "'$styleAttribute>$content</a>";
        } else {
            echo "<span class='patchpanel-route-step$class' data-route-zone='" .
                htmlescape($zone) . "'$styleAttribute>$content</span>";
        }
    }

    public static function renderForEndpoint(CommonDBTM $item): void
    {
        global $CFG_GLPI;

        $references = self::getEndpointRouteReferences($item);
        if (!$references) {
            echo "<div class='alert alert-info'>" .
                __('This object is not directly registered on a patch panel route.', 'patchpanel') .
                '</div>';
            echo "<div class='d-flex flex-wrap gap-2'>";
            echo "<a class='btn btn-outline-secondary' href='" .
                htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/routes.php') . "'>";
            echo "<i class='ti ti-route'></i> " .
                htmlescape(__('Open route explorer', 'patchpanel')) . '</a></div>';
            return;
        }
        foreach ($references as $reference) {
            self::renderEndpointRouteCard($reference);
        }
    }

    private static function getEndpointRouteReferences(CommonDBTM $item): array
    {
        global $DB;

        $references = [];
        if ($item instanceof NetworkPort || $item instanceof \Glpi\Socket) {
            foreach ($DB->request([
                'SELECT' => ['plugin_patchpanel_panelports_id', 'side'],
                'FROM' => PluginPatchpanelPortEndpoint::getTable(),
                'WHERE' => [
                    'itemtype' => $item->getType(),
                    'items_id' => $item->getID(),
                ],
            ]) as $row) {
                $references[] = [
                    'port_id' => (int) $row['plugin_patchpanel_panelports_id'],
                    'side' => (string) $row['side'],
                ];
            }
            if ($item instanceof NetworkPort) {
                foreach ($DB->request([
                    'SELECT' => ['id'],
                    'FROM' => \Glpi\Socket::getTable(),
                    'WHERE' => ['networkports_id' => $item->getID()],
                ]) as $socket) {
                    foreach ($DB->request([
                        'SELECT' => ['plugin_patchpanel_panelports_id', 'side'],
                        'FROM' => PluginPatchpanelPortEndpoint::getTable(),
                        'WHERE' => [
                            'itemtype' => \Glpi\Socket::class,
                            'items_id' => (int) $socket['id'],
                        ],
                    ]) as $row) {
                        $references[] = [
                            'port_id' => (int) $row['plugin_patchpanel_panelports_id'],
                            'side' => (string) $row['side'],
                        ];
                    }
                }
            }
            return self::uniqueEndpointRouteReferences($references);
        }

        $networkPortIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => NetworkPort::getTable(),
            'WHERE' => [
                'itemtype' => $item->getType(),
                'items_id' => $item->getID(),
                'is_deleted' => 0,
            ],
        ]) as $port) {
            $networkPortIds[] = (int) $port['id'];
        }

        if ($networkPortIds) {
            foreach ($DB->request([
                'SELECT' => ['plugin_patchpanel_panelports_id', 'side'],
                'FROM' => PluginPatchpanelPortEndpoint::getTable(),
                'WHERE' => [
                    'itemtype' => NetworkPort::class,
                    'items_id' => $networkPortIds,
                ],
            ]) as $row) {
                $references[] = [
                    'port_id' => (int) $row['plugin_patchpanel_panelports_id'],
                    'side' => (string) $row['side'],
                ];
            }
        }

        $socketIds = [];
        if ($networkPortIds) {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => \Glpi\Socket::getTable(),
                'WHERE' => ['networkports_id' => $networkPortIds],
            ]) as $socket) {
                $socketIds[] = (int) $socket['id'];
            }
        }

        if ($socketIds) {
            foreach ($DB->request([
                'SELECT' => ['plugin_patchpanel_panelports_id', 'side'],
                'FROM' => PluginPatchpanelPortEndpoint::getTable(),
                'WHERE' => [
                    'itemtype' => \Glpi\Socket::class,
                    'items_id' => $socketIds,
                ],
            ]) as $row) {
                $references[] = [
                    'port_id' => (int) $row['plugin_patchpanel_panelports_id'],
                    'side' => (string) $row['side'],
                ];
            }
        }

        return self::uniqueEndpointRouteReferences($references);
    }

    private static function uniqueEndpointRouteReferences(array $references): array
    {
        $unique = [];
        foreach ($references as $reference) {
            $key = (int) $reference['port_id'] . ':' . (string) $reference['side'];
            $unique[$key] = $reference;
        }
        return array_values($unique);
    }

    private static function renderEndpointRouteCard(array $reference): void
    {
        global $CFG_GLPI;

        $portId = (int) $reference['port_id'];
        $side = (string) $reference['side'];
        $port = new PluginPatchpanelPanelPort();
        $panel = new PluginPatchpanelPanel();
        if (!$port->getFromDB($portId)) {
            return;
        }
        $panelName = __('Patch panel', 'patchpanel');
        if ($panel->getFromDB((int) $port->fields['plugin_patchpanel_panels_id'])) {
            $panelName = $panel->fields['name'];
        }
        $sideLabel = $side === PluginPatchpanelPortEndpoint::REAR
            ? __('Rear side: permanent cabling', 'patchpanel')
            : __('Front side: patch cable', 'patchpanel');
        $portUrl = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panelport.form.php?id=' . $portId;
        $route = self::buildForPort($portId);
        $rearSocketId = ($side === PluginPatchpanelPortEndpoint::REAR
            && ($route['rear']['type'] ?? '') === \Glpi\Socket::class)
            ? (int) $route['rear']['id']
            : 0;
        $hasStaleEndpointDevice = self::socketHasDeviceWithoutNetworkPort($route);
        $hasEndpointDevice = $rearSocketId > 0 && (!empty($route['terminal']) || $hasStaleEndpointDevice);

        echo "<article class='card mb-3 patchpanel-endpoint-route'>";
        echo "<div class='card-header d-flex flex-wrap align-items-center gap-2'>";
        echo '<div><strong>' . htmlescape($panelName) . ' / #' .
            (int) $port->fields['number'] . '</strong>';
        echo '<div class="text-muted small">' . htmlescape($sideLabel) . '</div></div>';
        echo "<a class='btn btn-sm btn-outline-primary ms-auto' href='" . htmlescape($portUrl) . "'>";
        echo "<i class='ti ti-edit'></i> " . htmlescape(__('Manage connection', 'patchpanel')) . '</a>';
        if ($port->canUpdateItem()) {
            echo "<form method='post' action='" . htmlescape($portUrl) . "' class='m-0'>";
            echo Html::hidden('id', ['value' => $portId]);
            echo Html::hidden('side', ['value' => $side]);
            echo "<button class='btn btn-sm btn-outline-danger' type='submit' name='disconnect_endpoint' value='1'>";
            echo "<i class='ti ti-unlink'></i> " . htmlescape(__('Disconnect this side', 'patchpanel')) .
                '</button>';
            Html::closeForm();
            if ($hasEndpointDevice) {
                echo "<form method='post' action='" . htmlescape($portUrl) . "' class='m-0'>";
                echo Html::hidden('id', ['value' => $portId]);
                echo Html::hidden('socket_id', ['value' => $rearSocketId]);
                $buttonLabel = $hasStaleEndpointDevice
                    ? __('Clean up GLPI device selection', 'patchpanel')
                    : __('Disconnect end device from endpoint', 'patchpanel');
                echo "<button class='btn btn-sm btn-outline-danger' type='submit' name='disconnect_socket_device' value='1'>";
                echo "<i class='ti ti-device-desktop-off'></i> " . htmlescape($buttonLabel) . '</button>';
                Html::closeForm();
            }
        }
        echo '</div><div class="card-body">';
        self::renderConnectionDetails($route);
        self::render($portId);
        echo '</div></article>';
    }

    private static function renderConnectionDetails(array $route): void
    {
        echo "<section class='patchpanel-connection-details mb-3'>";
        echo '<h3>' . htmlescape(__('Connection details', 'patchpanel')) . '</h3>';
        echo "<div class='patchpanel-connection-grid'>";
        self::renderConnectionDetail(
            __('Rear permanent link', 'patchpanel'),
            self::getRearConnectionSummary($route)
        );
        self::renderConnectionDetail(
            __('Front patch link', 'patchpanel'),
            self::getFrontConnectionSummary($route)
        );
        self::renderConnectionDetail(
            __('Route health', 'patchpanel'),
            self::getRouteHealthSummary($route)
        );
        echo '</div></section>';
    }

    private static function renderConnectionDetail(string $label, array $summary): void
    {
        echo "<div class='patchpanel-connection-detail'>";
        echo '<strong>' . htmlescape($label) . '</strong>';
        echo "<span class='patchpanel-quality-status patchpanel-status-" .
            htmlescape($summary['status']) . "'>";
        echo "<i class='" . htmlescape($summary['icon']) . "'></i> " .
            htmlescape($summary['title']) . '</span>';
        if ($summary['text'] !== '') {
            echo '<small>' . htmlescape($summary['text']) . '</small>';
        }
        echo '</div>';
    }

    private static function getRearConnectionSummary(array $route): array
    {
        if (!$route['rear']) {
            return [
                'status' => 'partial',
                'icon' => 'ti ti-alert-triangle',
                'title' => __('No remote endpoint selected', 'patchpanel'),
                'text' => __('Choose a rear endpoint or connection point for permanent cabling.', 'patchpanel'),
            ];
        }
        if (!empty($route['rear']['broken'])) {
            return [
                'status' => 'warning',
                'icon' => 'ti ti-link-off',
                'title' => __('Broken endpoint reference', 'patchpanel'),
                'text' => (string) ($route['rear']['label'] ?? ''),
            ];
        }
        if ($route['terminal']) {
            $terminal = (string) ($route['terminal']['label'] ?? '');
            $port = (string) ($route['terminal']['port']['label'] ?? '');
            return [
                'status' => 'connected',
                'icon' => 'ti ti-circle-check',
                'title' => __('End device LAN port connected', 'patchpanel'),
                'text' => trim($terminal . ($port !== '' ? ' / ' . $port : '')),
            ];
        }

        $stale = self::socketHasDeviceWithoutNetworkPort($route);
        return [
            'status' => $stale ? 'warning' : 'partial',
            'icon' => $stale ? 'ti ti-alert-triangle' : 'ti ti-circle-dashed',
            'title' => $stale
                ? __('GLPI device selected only; no LAN port connected.', 'patchpanel')
                : __('No end device LAN port selected.', 'patchpanel'),
            'text' => $stale
                ? __('This device is not part of the PatchPanel route until a LAN/network port is selected.', 'patchpanel')
                : (string) ($route['rear']['label'] ?? ''),
        ];
    }

    private static function getFrontConnectionSummary(array $route): array
    {
        if (!$route['front']) {
            return [
                'status' => 'partial',
                'icon' => 'ti ti-alert-triangle',
                'title' => __('No switch/router port selected', 'patchpanel'),
                'text' => __('Choose a front network port for the patch cable.', 'patchpanel'),
            ];
        }
        if (!empty($route['front']['broken'])) {
            return [
                'status' => 'warning',
                'icon' => 'ti ti-link-off',
                'title' => __('Broken front network port reference', 'patchpanel'),
                'text' => (string) ($route['front']['label'] ?? ''),
            ];
        }

        $owner = '';
        if (($route['front']['type'] ?? '') === NetworkPort::class) {
            $frontPort = new NetworkPort();
            if ($frontPort->getFromDB((int) ($route['front']['id'] ?? 0))) {
                $ownerStep = self::stepForOwner($frontPort);
                $owner = $ownerStep['label'] ?? '';
            }
        }
        return [
            'status' => 'connected',
            'icon' => 'ti ti-circle-check',
            'title' => __('Switch/router port connected', 'patchpanel'),
            'text' => trim((string) ($route['front']['label'] ?? '') . ($owner !== '' ? ' / ' . $owner : '')),
        ];
    }

    private static function getRouteHealthSummary(array $route): array
    {
        if (!empty($route['has_broken_reference'])) {
            return [
                'status' => 'warning',
                'icon' => 'ti ti-link-off',
                'title' => __('Broken reference', 'patchpanel'),
                'text' => __('One or more route objects no longer exist or are inaccessible.', 'patchpanel'),
            ];
        }
        if ($route['rear'] && $route['front'] && $route['terminal']) {
            return [
                'status' => 'connected',
                'icon' => 'ti ti-circle-check',
                'title' => __('Complete route', 'patchpanel'),
                'text' => __('Remote endpoint, end-device LAN port and switch side are all known.', 'patchpanel'),
            ];
        }
        if ($route['rear'] && $route['front']) {
            return [
                'status' => 'partial',
                'icon' => 'ti ti-alert-triangle',
                'title' => __('Patch path known, end device incomplete', 'patchpanel'),
                'text' => __('The panel is patched, but the remote endpoint has no active end-device LAN port.', 'patchpanel'),
            ];
        }
        return [
            'status' => 'partial',
            'icon' => 'ti ti-alert-triangle',
            'title' => __('Incomplete route', 'patchpanel'),
            'text' => __('Complete both rear and front sides to trace the route.', 'patchpanel'),
        ];
    }

    private static function socketHasDeviceWithoutNetworkPort(array $route): bool
    {
        if (($route['rear']['type'] ?? '') !== \Glpi\Socket::class) {
            return false;
        }
        $socket = new \Glpi\Socket();
        if (!$socket->getFromDB((int) ($route['rear']['id'] ?? 0))) {
            return false;
        }
        return (string) ($socket->fields['itemtype'] ?? '') !== ''
            && (int) ($socket->fields['items_id'] ?? 0) > 0
            && (int) ($socket->fields['networkports_id'] ?? 0) <= 0;
    }
}
