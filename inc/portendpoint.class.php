<?php

use Glpi\DBAL\QuerySubQuery;

class PluginPatchpanelPortEndpoint extends CommonDBTM
{
    public static $rightname = 'networking';
    public $dohistory = true;

    public const REAR = 'rear';
    public const FRONT = 'front';
    public const REAR_NONE = 'none';
    public const REAR_SOCKET = 'socket';
    public const REAR_PANEL_PORT = 'panel_port';

    private static bool $syncingNativeNetworkPortLink = false;

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
        $panelLink = PluginPatchpanelPanelPortLink::getForPanelPort($portId);
        $peerPortId = $panelLink
            ? PluginPatchpanelPanelPortLink::getPeerPanelPortId($panelLink, $portId)
            : 0;
        $rearType = $panelLink
            ? self::REAR_PANEL_PORT
            : ((int) ($rear['items_id'] ?? 0) > 0 ? self::REAR_SOCKET : self::REAR_NONE);

        echo "<tr class='tab_bg_2'><th colspan='4'>" .
            __('Rear side: permanent cabling', 'patchpanel') . '</th></tr>';
        echo "<tr class='tab_bg_1'><td>" . __('Rear connection type', 'patchpanel') . "</td><td>";
        Dropdown::showFromArray('rear_endpoint_type', [
            self::REAR_NONE => __('Not connected', 'patchpanel'),
            self::REAR_SOCKET => __('Remote endpoint / connection point', 'patchpanel'),
            self::REAR_PANEL_PORT => __('Another patch panel port', 'patchpanel'),
        ], ['value' => $rearType]);
        echo '</td><td colspan="2"></td></tr>';
        echo "<tr class='tab_bg_1'><td>" . __('Remote endpoint / connection point', 'patchpanel') . "</td><td>";
        Dropdown::show('Glpi\\Socket', [
            'name' => 'rear_items_id',
            'value' => $rearType === self::REAR_SOCKET ? ($rear['items_id'] ?? 0) : 0,
            'condition' => self::getAvailableCondition(
                'Glpi\\Socket',
                $rearType === self::REAR_SOCKET ? (int) ($rear['items_id'] ?? 0) : 0
            ),
        ]);
        echo '</td><td>' . __('Linked patch panel port', 'patchpanel') . '</td><td>';
        Dropdown::showFromArray(
            'rear_panelport_id',
            self::getAvailablePanelPortOptions(
                $portId,
                $peerPortId
            ),
            [
                'value' => $peerPortId,
            ]
        );
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

    private static function getAvailablePanelPortOptions(int $portId, int $currentId): array
    {
        global $DB;

        $rearByPort = [];
        foreach ($DB->request([
            'SELECT' => [
                'plugin_patchpanel_panelports_id',
                'itemtype',
                'items_id',
            ],
            'FROM' => self::getTable(),
            'WHERE' => ['side' => self::REAR],
        ]) as $endpoint) {
            $rearByPort[(int) $endpoint['plugin_patchpanel_panelports_id']] = $endpoint;
        }

        $panels = [];
        $options = ['' => Dropdown::EMPTY_VALUE];
        foreach ($DB->request([
            'FROM' => PluginPatchpanelPanelPort::getTable(),
            'ORDER' => [
                'plugin_patchpanel_panels_id',
                'number',
            ],
        ]) as $row) {
            $candidateId = (int) $row['id'];
            if ($candidateId === $portId) {
                continue;
            }

            $rear = $rearByPort[$candidateId] ?? null;
            if ($rear) {
                continue;
            }
            $canonicalLink = PluginPatchpanelPanelPortLink::getForPanelPort($candidateId);
            if ($canonicalLink && $candidateId !== $currentId) {
                continue;
            }

            $panelId = (int) $row['plugin_patchpanel_panels_id'];
            if (!array_key_exists($panelId, $panels)) {
                $panel = new PluginPatchpanelPanel();
                $panels[$panelId] = $panel->getFromDB($panelId) && $panel->canViewItem()
                    ? $panel
                    : null;
            }
            $panel = $panels[$panelId];
            if (!$panel) {
                continue;
            }

            $panelName = trim((string) $panel->getName());
            if ($panelName === '') {
                $panelName = sprintf(__('Patch panel #%d', 'patchpanel'), $panelId);
            }
            $portName = sprintf(__('Port %d', 'patchpanel'), (int) $row['number']);
            $label = trim((string) ($row['label'] ?? ''));
            if ($label !== '' && strcasecmp($label, $portName) !== 0) {
                $portName .= ' · ' . $label;
            }
            $options[$candidateId] = $panelName . ' / ' . $portName;
        }

        return $options;
    }

    public static function saveForPort(
        int $portId,
        array $input,
        bool $manageTransaction = true
    ): bool
    {
        global $DB;

        $port = new PluginPatchpanelPanelPort();
        $port->check($portId, UPDATE);
        $existingEndpoints = self::getForPort($portId);
        $existingRear = $existingEndpoints[self::REAR] ?? [];
        $existingFront = $existingEndpoints[self::FRONT] ?? [];
        $oldLink = [
            'rear_port' => self::getRearNetworkPortId($existingRear),
            'front_port' => (int) ($existingFront['items_id'] ?? 0),
            'panel_port' => $portId,
        ];

        $desired = [
            self::REAR => [
                'itemtype' => \Glpi\Socket::class,
                'items_id' => (int) ($input['rear_items_id'] ?? 0),
                'cable_color' => null,
                'cables_id' => 0,
                'cable_label' => null,
            ],
            self::FRONT => [
                'itemtype' => NetworkPort::class,
                'items_id' => (int) ($input['front_items_id'] ?? 0),
                'cable_color' => self::normalizeColor($input['front_cable_color'] ?? ''),
                'cables_id' => max(
                    0,
                    (int) ($input['front_cables_id'] ?? ($existingFront['cables_id'] ?? 0))
                ),
                // Cable identification is the panel-port label. Keep the old
                // database column empty for installations upgraded from a
                // version that exposed a separate Cable ID field.
                'cable_label' => null,
            ],
        ];

        if ($manageTransaction) {
            $DB->beginTransaction();
        }
        try {
            foreach ($desired as $side => $endpoint) {
                self::saveSide($portId, $side, $endpoint);
            }
            self::syncNativeNetworkPortLink($oldLink, self::getForPort($portId));
            if ($manageTransaction) {
                $DB->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($manageTransaction) {
                $DB->rollBack();
            }
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

    public static function disconnectSide(int $portId, string $side): bool
    {
        global $DB;

        if (!in_array($side, [self::REAR, self::FRONT], true)) {
            throw new InvalidArgumentException(__('Invalid endpoint side.', 'patchpanel'));
        }

        $port = new PluginPatchpanelPanelPort();
        $port->check($portId, UPDATE);
        $endpoints = self::getForPort($portId);
        $existing = $endpoints[$side] ?? [];
        self::clearNativeNetworkPortLink([
            'rear_port' => self::getRearNetworkPortId($endpoints[self::REAR] ?? []),
            'front_port' => (int) ($endpoints[self::FRONT]['items_id'] ?? 0),
            'panel_port' => $portId,
        ]);
        if (
            $side === self::REAR
            && ($existing['itemtype'] ?? '') === \Glpi\Socket::class
            && (int) ($existing['items_id'] ?? 0) > 0
        ) {
            self::disconnectSocketDevice((int) $existing['items_id']);
        }
        $deleted = $DB->delete(self::getTable(), [
            'plugin_patchpanel_panelports_id' => $portId,
            'side' => $side,
        ]);
        if ($deleted) {
            self::syncNativeNetworkPortLink(
                ['rear_port' => 0, 'front_port' => 0, 'panel_port' => $portId],
                self::getForPort($portId)
            );
        }
        return $deleted;
    }

    public static function disconnectSocketDevice(int $socketId): bool
    {
        $socket = new \Glpi\Socket();
        $socket->check($socketId, UPDATE);
        return self::clearSocketDeviceFields($socket);
    }

    public static function cleanupSocketDeviceSelectionWhenPortIsEmpty(CommonDBTM $item): void
    {
        if (!$item instanceof \Glpi\Socket || (int) $item->getID() <= 0) {
            return;
        }

        $input = is_array($item->input ?? null) ? $item->input : [];
        $currentNetworkPortId = (int) ($item->fields['networkports_id'] ?? 0);
        $hasDeviceSelection = (string) ($item->fields['itemtype'] ?? '') !== ''
            && (int) ($item->fields['items_id'] ?? 0) > 0;
        if (!$hasDeviceSelection) {
            return;
        }

        if (array_key_exists('networkports_id', $input) && (int) $input['networkports_id'] > 0) {
            if (
                $currentNetworkPortId > 0
                || (string) ($input['itemtype'] ?? $item->fields['itemtype']) !== (string) $item->fields['itemtype']
                || (int) ($input['items_id'] ?? $item->fields['items_id']) !== (int) $item->fields['items_id']
            ) {
                return;
            }
        } elseif ($currentNetworkPortId > 0) {
            return;
        }

        self::clearSocketDeviceFields($item);
    }

    public static function synchronizeNativeNetworkPortLinksForSocket(CommonDBTM $item): void
    {
        if (!$item instanceof \Glpi\Socket || (int) $item->getID() <= 0) {
            return;
        }

        self::synchronizeNativeNetworkPortLinksForSocketId((int) $item->getID());
    }

    public static function synchronizeNativeNetworkPortLinksForSocketId(int $socketId): void
    {
        global $DB;

        if ($socketId <= 0) {
            return;
        }

        $endpointTable = self::getTable();
        foreach ($DB->request([
            'FROM' => $endpointTable,
            'WHERE' => [
                'side' => self::REAR,
                'itemtype' => \Glpi\Socket::class,
                'items_id' => $socketId,
            ],
        ]) as $rearEndpoint) {
            $frontEndpoint = $DB->request([
                'FROM' => $endpointTable,
                'WHERE' => [
                    'plugin_patchpanel_panelports_id' => (int) $rearEndpoint['plugin_patchpanel_panelports_id'],
                    'side' => self::FRONT,
                    'itemtype' => NetworkPort::class,
                ],
                'LIMIT' => 1,
            ])->current();

            self::syncNativeNetworkPortLink(
                ['rear_port' => 0, 'front_port' => (int) ($frontEndpoint['items_id'] ?? 0)],
                [
                    self::REAR => $rearEndpoint,
                    self::FRONT => $frontEndpoint ?: [],
                ]
            );
        }
    }

    public static function synchronizeNativeNetworkPortLinksForPanel(int $panelId): void
    {
        global $DB;

        if ($panelId <= 0) {
            return;
        }

        $endpointTable = self::getTable();
        $portTable = PluginPatchpanelPanelPort::getTable();
        $socketType = $DB->escape(\Glpi\Socket::class);
        $result = $DB->doQuery(
            "SELECT DISTINCT e.items_id
             FROM `$endpointTable` e
             INNER JOIN `$portTable` p
               ON p.id = e.plugin_patchpanel_panelports_id
             WHERE p.plugin_patchpanel_panels_id = $panelId
               AND e.side = '" . self::REAR . "'
               AND e.itemtype = '$socketType'"
        );
        while ($result && ($row = $result->fetch_assoc())) {
            self::synchronizeNativeNetworkPortLinksForSocketId((int) ($row['items_id'] ?? 0));
        }
    }

    public static function synchronizeAllNativeNetworkPortLinks(): void
    {
        global $DB;

        foreach ($DB->request([
            'FROM' => self::getTable(),
            'WHERE' => [
                'side' => self::FRONT,
                'itemtype' => NetworkPort::class,
            ],
        ]) as $frontEndpoint) {
            $panelPortId = (int) ($frontEndpoint['plugin_patchpanel_panelports_id'] ?? 0);
            self::syncNativeNetworkPortLink(
                [
                    'rear_port' => self::getLegacyNativeTargetForPanelPort($panelPortId),
                    'front_port' => (int) ($frontEndpoint['items_id'] ?? 0),
                    'panel_port' => $panelPortId,
                ],
                self::getForPort($panelPortId)
            );
        }
    }

    public static function cleanupPanelNetworkPortsForPanelPorts(array $panelPortIds): void
    {
        global $DB;

        $panelPortIds = array_values(array_filter(array_map('intval', $panelPortIds)));
        if (!$panelPortIds) {
            return;
        }

        $networkPortIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => NetworkPort::getTable(),
            'WHERE' => [
                'itemtype' => PluginPatchpanelPanelPort::class,
                'items_id' => $panelPortIds,
            ],
        ]) as $row) {
            $networkPortIds[] = (int) $row['id'];
        }

        if (!$networkPortIds) {
            return;
        }

        $wired = new NetworkPort_NetworkPort();
        foreach ($networkPortIds as $networkPortId) {
            $wired->disconnectFrom($networkPortId);
        }
        $DB->delete(NetworkPort::getTable(), ['id' => $networkPortIds]);
    }

    public static function cleanupFrontEndpointAfterNativeNetworkPortDisconnect(CommonDBTM $item): void
    {
        global $DB;

        if (!$item instanceof NetworkPort_NetworkPort) {
            return;
        }
        if (self::$syncingNativeNetworkPortLink) {
            return;
        }

        $portIds = array_values(array_filter([
            (int) ($item->fields['networkports_id_1'] ?? 0),
            (int) ($item->fields['networkports_id_2'] ?? 0),
        ]));
        if (count($portIds) !== 2) {
            return;
        }

        foreach ($DB->request([
            'FROM' => self::getTable(),
            'WHERE' => [
                'side' => self::FRONT,
                'itemtype' => NetworkPort::class,
                'items_id' => $portIds,
            ],
        ]) as $frontEndpoint) {
            $frontPortId = (int) ($frontEndpoint['items_id'] ?? 0);
            $nativeTargetId = $frontPortId === $portIds[0] ? $portIds[1] : $portIds[0];
            $panelPortId = (int) ($frontEndpoint['plugin_patchpanel_panelports_id'] ?? 0);

            if ($nativeTargetId !== self::getExpectedNativeTargetForPanelPort($panelPortId)) {
                continue;
            }

            $DB->delete(self::getTable(), ['id' => (int) $frontEndpoint['id']]);
            self::recordNativeDisconnectAudit($panelPortId, $frontEndpoint);
        }
    }

    public static function cleanupFrontEndpointsAfterOwnerSoftDelete(CommonDBTM $item): void
    {
        global $DB;

        if ((int) $item->getID() <= 0 || (int) ($item->fields['is_deleted'] ?? 0) === 0) {
            return;
        }

        $portIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => NetworkPort::getTable(),
            'WHERE' => [
                'itemtype' => $item->getType(),
                'items_id' => (int) $item->getID(),
            ],
        ]) as $row) {
            $portIds[] = (int) $row['id'];
        }
        self::cleanupFrontEndpointsForNetworkPorts($portIds);
    }

    public static function cleanupFrontEndpointAfterPortSoftDelete(CommonDBTM $item): void
    {
        if (!$item instanceof NetworkPort || (int) ($item->fields['is_deleted'] ?? 0) === 0) {
            return;
        }
        self::cleanupFrontEndpointsForNetworkPorts([(int) $item->getID()]);
    }

    public static function cleanupRearEndpointAfterSocketPurge(CommonDBTM $item): void
    {
        global $DB;

        if (!$item instanceof \Glpi\Socket || (int) $item->getID() <= 0) {
            return;
        }
        $DB->delete(self::getTable(), [
            'side' => self::REAR,
            'itemtype' => \Glpi\Socket::class,
            'items_id' => (int) $item->getID(),
        ]);
    }

    public static function cleanupConnectionsForPanelSoftDelete(int $panelId): void
    {
        global $DB;

        $panelPortIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => PluginPatchpanelPanelPort::getTable(),
            'WHERE' => ['plugin_patchpanel_panels_id' => $panelId],
        ]) as $row) {
            $panelPortIds[] = (int) $row['id'];
        }
        foreach ($panelPortIds as $panelPortId) {
            PluginPatchpanelPanelPortLink::deleteForPanelPort($panelPortId);
            $endpoints = self::getForPort($panelPortId);
            if (isset($endpoints[self::FRONT])) {
                self::clearNativeNetworkPortLink([
                    'panel_port' => $panelPortId,
                    'rear_port' => 0,
                    'front_port' => (int) ($endpoints[self::FRONT]['items_id'] ?? 0),
                ]);
                self::recordNativeDisconnectAudit($panelPortId, $endpoints[self::FRONT]);
            }
        }
        if ($panelPortIds) {
            $DB->delete(self::getTable(), ['plugin_patchpanel_panelports_id' => $panelPortIds]);
        }
    }

    private static function cleanupFrontEndpointsForNetworkPorts(array $networkPortIds): void
    {
        global $DB;

        $networkPortIds = array_values(array_unique(array_filter(array_map('intval', $networkPortIds))));
        if (!$networkPortIds) {
            return;
        }

        foreach ($DB->request([
            'FROM' => self::getTable(),
            'WHERE' => [
                'side' => self::FRONT,
                'itemtype' => NetworkPort::class,
                'items_id' => $networkPortIds,
            ],
        ]) as $frontEndpoint) {
            $panelPortId = (int) ($frontEndpoint['plugin_patchpanel_panelports_id'] ?? 0);
            self::clearNativeNetworkPortLink([
                'panel_port' => $panelPortId,
                'rear_port' => 0,
                'front_port' => (int) ($frontEndpoint['items_id'] ?? 0),
            ]);
            $DB->delete(self::getTable(), ['id' => (int) $frontEndpoint['id']]);
            self::recordNativeDisconnectAudit($panelPortId, $frontEndpoint);
        }
    }

    public static function syncFrontEndpointAfterNativeNetworkPortConnect(CommonDBTM $item): void
    {
        if (!$item instanceof NetworkPort_NetworkPort) {
            return;
        }
        if (self::$syncingNativeNetworkPortLink) {
            return;
        }

        $portIds = array_values(array_filter([
            (int) ($item->fields['networkports_id_1'] ?? 0),
            (int) ($item->fields['networkports_id_2'] ?? 0),
        ]));
        if (count($portIds) !== 2) {
            return;
        }

        $nativePorts = [];
        foreach ($portIds as $portId) {
            $networkPort = new NetworkPort();
            if (!$networkPort->getFromDB($portId)) {
                self::rollbackInvalidNativeNetworkPortLink($item);
                return;
            }
            $nativePorts[] = $networkPort;
        }

        $shadowIndexes = [];
        foreach ($nativePorts as $index => $networkPort) {
            if ((string) ($networkPort->fields['itemtype'] ?? '') === PluginPatchpanelPanelPort::class) {
                $shadowIndexes[] = $index;
            }
        }
        if (!$shadowIndexes) {
            return;
        }

        // PatchPanel shadow ports are implementation details. A native link is
        // valid only between exactly one active shadow and one real GLPI port.
        if (count($shadowIndexes) !== 1) {
            self::rollbackInvalidNativeNetworkPortLink($item);
            return;
        }

        $targetIndex = $shadowIndexes[0];
        $frontIndex = $targetIndex === 0 ? 1 : 0;
        $panelPortId = (int) ($nativePorts[$targetIndex]->fields['items_id'] ?? 0);
        if (
            !self::isActivePanelPort($panelPortId)
            || !self::canUseNativePortAsFrontEndpoint($portIds[$frontIndex])
            || $portIds[$targetIndex] !== self::getExpectedNativeTargetForPanelPort($panelPortId)
        ) {
            self::rollbackInvalidNativeNetworkPortLink($item);
            return;
        }

        self::replaceFrontEndpointFromNativeLink($panelPortId, $portIds[$frontIndex]);
    }

    private static function rollbackInvalidNativeNetworkPortLink(NetworkPort_NetworkPort $item): void
    {
        global $DB;

        $relationId = (int) $item->getID();
        if ($relationId > 0) {
            $DB->delete(NetworkPort_NetworkPort::getTable(), ['id' => $relationId]);
            if (countElementsInTable(NetworkPort_NetworkPort::getTable(), ['id' => $relationId])) {
                throw new RuntimeException('Invalid native PatchPanel relation rollback failed');
            }
            return;
        }

        $portIds = array_values(array_filter([
            (int) ($item->fields['networkports_id_1'] ?? 0),
            (int) ($item->fields['networkports_id_2'] ?? 0),
        ]));
        if (count($portIds) === 2) {
            $DB->delete(NetworkPort_NetworkPort::getTable(), [
                'OR' => [
                    [
                        'networkports_id_1' => $portIds[0],
                        'networkports_id_2' => $portIds[1],
                    ],
                    [
                        'networkports_id_1' => $portIds[1],
                        'networkports_id_2' => $portIds[0],
                    ],
                ],
            ]);
            $remaining = $DB->request([
                'FROM' => NetworkPort_NetworkPort::getTable(),
                'WHERE' => [
                    'OR' => [
                        [
                            'networkports_id_1' => $portIds[0],
                            'networkports_id_2' => $portIds[1],
                        ],
                        [
                            'networkports_id_1' => $portIds[1],
                            'networkports_id_2' => $portIds[0],
                        ],
                    ],
                ],
                'LIMIT' => 1,
            ])->count();
            if ($remaining) {
                throw new RuntimeException('Invalid native PatchPanel relation rollback failed');
            }
        }
    }

    private static function isActivePanelPort(int $panelPortId): bool
    {
        if ($panelPortId <= 0) {
            return false;
        }

        $panelPort = new PluginPatchpanelPanelPort();
        if (!$panelPort->getFromDB($panelPortId)) {
            return false;
        }

        $panel = new PluginPatchpanelPanel();
        return $panel->getFromDB((int) ($panelPort->fields['plugin_patchpanel_panels_id'] ?? 0))
            && (int) ($panel->fields['is_deleted'] ?? 0) === 0;
    }

    private static function clearSocketDeviceFields(\Glpi\Socket $socket): bool
    {
        global $DB;

        self::clearNativeLinksForSocket($socket);

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $cleared = [
            'itemtype' => null,
            'items_id' => 0,
            'networkports_id' => 0,
            'date_mod' => $now,
        ];
        $updated = $DB->update(\Glpi\Socket::getTable(), $cleared, ['id' => (int) $socket->getID()]);
        if ($updated) {
            foreach ($cleared as $field => $value) {
                $socket->fields[$field] = $value;
            }
        }
        return $updated;
    }

    private static function clearNativeLinksForSocket(\Glpi\Socket $socket): void
    {
        global $DB;

        $socketPortId = (int) ($socket->fields['networkports_id'] ?? 0);
        if ($socketPortId <= 0) {
            return;
        }

        $endpointTable = self::getTable();
        foreach ($DB->request([
            'FROM' => $endpointTable,
            'WHERE' => [
                'side' => self::REAR,
                'itemtype' => \Glpi\Socket::class,
                'items_id' => (int) $socket->getID(),
            ],
        ]) as $rearEndpoint) {
            $frontEndpoint = $DB->request([
                'FROM' => $endpointTable,
                'WHERE' => [
                    'plugin_patchpanel_panelports_id' => (int) $rearEndpoint['plugin_patchpanel_panelports_id'],
                    'side' => self::FRONT,
                    'itemtype' => NetworkPort::class,
                ],
                'LIMIT' => 1,
            ])->current();
            self::clearNativeNetworkPortLink([
                'rear_port' => $socketPortId,
                'front_port' => (int) ($frontEndpoint['items_id'] ?? 0),
                'panel_port' => (int) ($rearEndpoint['plugin_patchpanel_panelports_id'] ?? 0),
            ]);
        }
    }

    private static function getSocketNetworkPortId(int $socketId): int
    {
        if ($socketId <= 0) {
            return 0;
        }

        $socket = new \Glpi\Socket();
        if (!$socket->getFromDB($socketId)) {
            return 0;
        }
        return (int) ($socket->fields['networkports_id'] ?? 0);
    }

    /**
     * Resolve both current socket endpoints and legacy 0.1.4 device-port
     * endpoints so an old native link can be removed safely. New rear
     * endpoints are still restricted to sockets by validation.
     */
    private static function getRearNetworkPortId(array $endpoint): int
    {
        if (($endpoint['itemtype'] ?? '') === NetworkPort::class) {
            return (int) ($endpoint['items_id'] ?? 0);
        }
        if (($endpoint['itemtype'] ?? '') === \Glpi\Socket::class) {
            return self::getSocketNetworkPortId((int) ($endpoint['items_id'] ?? 0));
        }
        return 0;
    }

    private static function getPanelPortIdsForNativeTarget(int $targetPortId): array
    {
        if ($targetPortId <= 0) {
            return [];
        }

        $panelPortIds = [];
        $targetPort = new NetworkPort();
        if (
            $targetPort->getFromDB($targetPortId)
            && (string) ($targetPort->fields['itemtype'] ?? '') === PluginPatchpanelPanelPort::class
            && (int) ($targetPort->fields['is_deleted'] ?? 0) === 0
        ) {
            $panelPortIds[] = (int) ($targetPort->fields['items_id'] ?? 0);
        }

        return array_values(array_unique(array_filter($panelPortIds)));
    }

    private static function getExpectedNativeTargetForPanelPort(int $panelPortId): int
    {
        if ($panelPortId <= 0) {
            return 0;
        }

        return self::getPanelNetworkPortId($panelPortId);
    }

    private static function getLegacyNativeTargetForPanelPort(int $panelPortId): int
    {
        $endpoints = self::getForPort($panelPortId);
        return self::getRearNetworkPortId($endpoints[self::REAR] ?? []);
    }

    private static function recordNativeDisconnectAudit(int $panelPortId, array $frontEndpoint): void
    {
        $panelPort = new PluginPatchpanelPanelPort();
        if (!$panelPort->getFromDB($panelPortId)) {
            return;
        }

        PluginPatchpanelAudit::record(
            (int) ($panelPort->fields['plugin_patchpanel_panels_id'] ?? 0),
            $panelPortId,
            'disconnect',
            'glpi-networkport',
            __('Front endpoint removed after GLPI native network port disconnect.', 'patchpanel'),
            [self::FRONT => $frontEndpoint],
            []
        );
    }

    private static function recordNativeConnectAudit(
        int $panelPortId,
        array $oldFrontEndpoint,
        array $newFrontEndpoint
    ): void
    {
        $panelPort = new PluginPatchpanelPanelPort();
        if (!$panelPort->getFromDB($panelPortId)) {
            return;
        }

        PluginPatchpanelAudit::record(
            (int) ($panelPort->fields['plugin_patchpanel_panels_id'] ?? 0),
            $panelPortId,
            'connect',
            'glpi-networkport',
            __('Front endpoint updated after GLPI native network port connect.', 'patchpanel'),
            $oldFrontEndpoint ? [self::FRONT => $oldFrontEndpoint] : [],
            [self::FRONT => $newFrontEndpoint]
        );
    }

    private static function replaceFrontEndpointFromNativeLink(int $panelPortId, int $frontPortId): void
    {
        global $DB;

        if ($panelPortId <= 0 || $frontPortId <= 0 || !self::canUseNativePortAsFrontEndpoint($frontPortId)) {
            return;
        }

        $table = self::getTable();
        $existingFront = $DB->request([
            'FROM' => $table,
            'WHERE' => [
                'plugin_patchpanel_panelports_id' => $panelPortId,
                'side' => self::FRONT,
            ],
            'LIMIT' => 1,
        ])->current() ?: [];
        if ((int) ($existingFront['items_id'] ?? 0) === $frontPortId) {
            return;
        }

        $newFrontEndpoint = [
            'plugin_patchpanel_panelports_id' => $panelPortId,
            'side' => self::FRONT,
            'itemtype' => NetworkPort::class,
            'items_id' => $frontPortId,
            'cables_id' => 0,
            'cable_color' => null,
            'cable_label' => null,
        ];
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $DB->delete($table, [
            'side' => self::FRONT,
            'itemtype' => NetworkPort::class,
            'items_id' => $frontPortId,
        ]);
        $DB->delete($table, [
            'plugin_patchpanel_panelports_id' => $panelPortId,
            'side' => self::FRONT,
        ]);
        $DB->insert($table, $newFrontEndpoint + [
            'date_mod' => $now,
            'date_creation' => $now,
        ]);

        self::recordNativeConnectAudit($panelPortId, $existingFront, $newFrontEndpoint);
    }

    private static function canUseNativePortAsFrontEndpoint(int $networkPortId): bool
    {
        return self::isUsableFrontNetworkPortId($networkPortId);
    }

    public static function isUsableFrontNetworkPortId(int $networkPortId): bool
    {
        $networkPort = new NetworkPort();
        if (
            !$networkPort->getFromDB($networkPortId)
            || (int) ($networkPort->fields['is_deleted'] ?? 0) !== 0
            || (string) ($networkPort->fields['itemtype'] ?? '') === PluginPatchpanelPanelPort::class
        ) {
            return false;
        }
        $ownerType = (string) ($networkPort->fields['itemtype'] ?? '');
        $ownerId = (int) ($networkPort->fields['items_id'] ?? 0);
        if (!class_exists($ownerType) || !is_a($ownerType, CommonDBTM::class, true) || $ownerId <= 0) {
            return false;
        }
        $owner = new $ownerType();
        return $owner->getFromDB($ownerId)
            && (!array_key_exists('is_deleted', $owner->fields) || (int) $owner->fields['is_deleted'] === 0);
    }

    private static function syncNativeNetworkPortLink(array $oldLink, array $endpoints): void
    {
        $newLink = [
            'rear_port' => self::getRearNetworkPortId($endpoints[self::REAR] ?? []),
            'front_port' => (int) ($endpoints[self::FRONT]['items_id'] ?? 0),
            'panel_port' => (int) (
                ($endpoints[self::FRONT]['plugin_patchpanel_panelports_id'] ?? 0)
                ?: ($endpoints[self::REAR]['plugin_patchpanel_panelports_id'] ?? 0)
            ),
        ];

        if (
            (int) ($oldLink['rear_port'] ?? 0) !== (int) $newLink['rear_port']
            || (int) ($oldLink['front_port'] ?? 0) !== (int) $newLink['front_port']
            || (int) ($oldLink['panel_port'] ?? 0) !== (int) $newLink['panel_port']
        ) {
            self::clearNativeNetworkPortLink($oldLink);
        }

        self::clearNativeEndpointConflicts($newLink);
        self::ensureNativeNetworkPortLink($newLink);
    }

    private static function clearNativeEndpointConflicts(array $link): void
    {
        $frontPortId = (int) ($link['front_port'] ?? 0);
        $panelNetworkPortId = self::getPanelNetworkPortId((int) ($link['panel_port'] ?? 0));
        $targetPortId = $panelNetworkPortId;
        $wired = new NetworkPort_NetworkPort();

        if ($frontPortId > 0 && $targetPortId <= 0) {
            self::disconnectNativePort($wired, $frontPortId);
        }
        if ($targetPortId > 0 && $frontPortId <= 0) {
            self::disconnectNativePort($wired, $targetPortId);
        }
    }

    private static function clearNativeNetworkPortLink(array $link): void
    {
        $rearPortId = (int) ($link['rear_port'] ?? 0);
        $frontPortId = (int) ($link['front_port'] ?? 0);
        $panelNetworkPortId = self::getPanelNetworkPortId((int) ($link['panel_port'] ?? 0));
        if (($rearPortId <= 0 && $panelNetworkPortId <= 0) || $frontPortId <= 0) {
            return;
        }

        $wired = new NetworkPort_NetworkPort();
        $opposite = (int) $wired->getOppositeContact($frontPortId);
        if ($opposite === $rearPortId || $opposite === $panelNetworkPortId) {
            self::disconnectNativePort($wired, $frontPortId);
        }
    }

    private static function ensureNativeNetworkPortLink(array $link): void
    {
        $frontPortId = (int) ($link['front_port'] ?? 0);
        $targetPortId = self::ensurePanelNetworkPort((int) ($link['panel_port'] ?? 0));
        if ($targetPortId <= 0 || $frontPortId <= 0) {
            return;
        }

        $wired = new NetworkPort_NetworkPort();
        if ((int) $wired->getOppositeContact($frontPortId) === $targetPortId) {
            return;
        }

        self::disconnectNativePort($wired, $frontPortId);
        self::disconnectNativePort($wired, $targetPortId);
        if (!self::connectNativePorts($wired, $frontPortId, $targetPortId)) {
            throw new RuntimeException('Native network port link update failed');
        }
    }

    private static function disconnectNativePort(NetworkPort_NetworkPort $wired, int $networkPortId): void
    {
        self::$syncingNativeNetworkPortLink = true;
        try {
            $wired->disconnectFrom($networkPortId);
        } finally {
            self::$syncingNativeNetworkPortLink = false;
        }
    }

    private static function connectNativePorts(
        NetworkPort_NetworkPort $wired,
        int $frontPortId,
        int $targetPortId
    ): int {
        self::$syncingNativeNetworkPortLink = true;
        try {
            return (int) $wired->add([
                'networkports_id_1' => $frontPortId,
                'networkports_id_2' => $targetPortId,
            ]);
        } finally {
            self::$syncingNativeNetworkPortLink = false;
        }
    }

    private static function getPanelNetworkPortId(int $panelPortId): int
    {
        if ($panelPortId <= 0) {
            return 0;
        }

        $ports = (new NetworkPort())->find([
            'itemtype' => PluginPatchpanelPanelPort::class,
            'items_id' => $panelPortId,
            'is_deleted' => 0,
        ], [], 1);
        $port = reset($ports);
        return (int) ($port['id'] ?? 0);
    }

    private static function ensurePanelNetworkPort(int $panelPortId): int
    {
        if ($panelPortId <= 0) {
            return 0;
        }

        $existingId = self::getPanelNetworkPortId($panelPortId);
        if ($existingId > 0) {
            return $existingId;
        }

        $panelPort = new PluginPatchpanelPanelPort();
        if (!$panelPort->getFromDB($panelPortId)) {
            return 0;
        }

        $panelName = PluginPatchpanelPanel::getTypeName(1);
        $panel = new PluginPatchpanelPanel();
        if ($panel->getFromDB((int) ($panelPort->fields['plugin_patchpanel_panels_id'] ?? 0))) {
            $panelName = $panel->getName();
        }

        $networkPort = new NetworkPort();
        return (int) $networkPort->add([
            'itemtype' => PluginPatchpanelPanelPort::class,
            'items_id' => $panelPortId,
            'entities_id' => (int) ($panel->fields['entities_id'] ?? 0),
            'is_recursive' => (int) ($panel->fields['is_recursive'] ?? 0),
            'name' => sprintf(
                '%s - %s %d',
                $panelName,
                __('Port', 'patchpanel'),
                (int) ($panelPort->fields['number'] ?? $panelPortId)
            ),
            'instantiation_type' => 'NetworkPortEthernet',
        ]);
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
            self::clearPreviousRearSocketDevice($side, $existing, $endpoint);
            if ($existing) {
                $DB->delete($table, ['id' => $existing['id']]);
            }
            return;
        }

        self::validateEndpointForPort($portId, $side, $endpoint['itemtype'], (int) $endpoint['items_id']);

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $values = $endpoint + [
            'plugin_patchpanel_panelports_id' => $portId,
            'side' => $side,
            'date_mod' => $now,
        ];
        if ($existing) {
            self::clearPreviousRearSocketDevice($side, $existing, $endpoint);
            $DB->update($table, $values, ['id' => $existing['id']]);
            return;
        }
        $values['date_creation'] = $now;
        $DB->insert($table, $values);
    }

    private static function clearPreviousRearSocketDevice(string $side, $existing, array $endpoint): void
    {
        if (
            $side !== self::REAR
            || empty($existing)
            || ($existing['itemtype'] ?? '') !== \Glpi\Socket::class
            || (int) ($existing['items_id'] ?? 0) <= 0
        ) {
            return;
        }

        if (
            ($endpoint['itemtype'] ?? '') === \Glpi\Socket::class
            && (int) ($endpoint['items_id'] ?? 0) === (int) $existing['items_id']
        ) {
            return;
        }

        self::disconnectSocketDevice((int) $existing['items_id']);
    }

    private static function validateEndpointForPort(
        int $portId,
        string $side,
        string $itemtype,
        int $itemsId
    ): void
    {
        global $DB;

        $expectedTypes = $side === self::REAR
            ? [\Glpi\Socket::class]
            : [NetworkPort::class];
        if (!in_array($itemtype, $expectedTypes, true)) {
            throw new InvalidArgumentException(__('Invalid endpoint type.', 'patchpanel'));
        }
        if ($side === self::REAR) {
            PluginPatchpanelPanelPortLink::assertRearSocketAllowed($portId);
        }

        $item = new $itemtype();
        if (!$item->getFromDB($itemsId) || !$item->canViewItem()) {
            throw new InvalidArgumentException(__('The selected endpoint does not exist or is inaccessible.', 'patchpanel'));
        }
        if ($item instanceof NetworkPort && !self::isUsableFrontNetworkPortId($itemsId)) {
            throw new InvalidArgumentException(__('The selected network port or its owner is deleted.', 'patchpanel'));
        }

        $used = $DB->request([
            'SELECT' => ['plugin_patchpanel_panelports_id'],
            'FROM' => self::getTable(),
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
                'NOT' => ['plugin_patchpanel_panelports_id' => $portId],
            ],
            'LIMIT' => 1,
        ])->current();
        if ($used) {
            throw new InvalidArgumentException(__('The selected endpoint is already assigned to another panel port.', 'patchpanel'));
        }
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
        return self::createTabEntry(
            __('Patch panel routes', 'patchpanel'),
            icon: PluginPatchpanelPanel::getIcon()
        );
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
