<?php

final class PluginPatchpanelPanelPortLink extends CommonDBTM
{
    public static $rightname = 'networking';
    public $dohistory = true;

    public static function getTypeName($nb = 0): string
    {
        return _n('Panel-to-panel link', 'Panel-to-panel links', $nb, 'patchpanel');
    }

    /**
     * Return the normalized endpoint order used by the database.
     *
     * @return array{0: int, 1: int}
     */
    public static function normalizePair(int $portIdA, int $portIdB): array
    {
        return $portIdA <= $portIdB
            ? [$portIdA, $portIdB]
            : [$portIdB, $portIdA];
    }

    public static function getForPanelPort(int $portId, bool $activeOnly = true): ?array
    {
        global $DB;

        if ($portId <= 0 || !$DB->tableExists(self::getTable())) {
            return null;
        }

        $where = [
            'OR' => [
                'panelports_id_a' => $portId,
                'panelports_id_b' => $portId,
            ],
        ];
        if ($activeOnly) {
            $where['is_active'] = 1;
        }

        $row = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => $where,
            'ORDER' => ['id ASC'],
            'LIMIT' => 1,
        ])->current();

        return $row ?: null;
    }

    public static function getPeerPanelPortId(array $link, int $portId): int
    {
        $portIdA = (int) ($link['panelports_id_a'] ?? 0);
        $portIdB = (int) ($link['panelports_id_b'] ?? 0);

        if ($portId === $portIdA) {
            return $portIdB;
        }
        if ($portId === $portIdB) {
            return $portIdA;
        }
        return 0;
    }

    public static function hasActiveLink(int $portId): bool
    {
        return self::getForPanelPort($portId) !== null;
    }

    public static function assertRearSocketAllowed(int $portId): void
    {
        if (self::hasActiveLink($portId)) {
            throw new DomainException(
                __('This panel port is already permanently linked to another patch panel.', 'patchpanel')
            );
        }
    }

    public static function assertPanelLinkAllowed(int $portId): void
    {
        global $DB;

        if ($portId <= 0) {
            throw new InvalidArgumentException(__('The panel port is invalid.', 'patchpanel'));
        }
        if (self::hasActiveLink($portId)) {
            throw new DomainException(
                __('This panel port is already permanently linked to another patch panel.', 'patchpanel')
            );
        }

        $rearEndpoint = $DB->request([
            'SELECT' => ['id'],
            'FROM' => PluginPatchpanelPortEndpoint::getTable(),
            'WHERE' => [
                'plugin_patchpanel_panelports_id' => $portId,
                'side' => PluginPatchpanelPortEndpoint::REAR,
                'itemtype' => \Glpi\Socket::class,
                'NOT' => ['items_id' => 0],
            ],
            'LIMIT' => 1,
        ])->current();
        if ($rearEndpoint) {
            throw new DomainException(
                __('This panel port already has a rear GLPI connection point.', 'patchpanel')
            );
        }
    }

    public static function saveForPorts(int $portId, int $peerPortId, array $input): bool
    {
        global $DB;

        if ($portId === $peerPortId) {
            throw new InvalidArgumentException(__('A panel port cannot be linked to itself.', 'patchpanel'));
        }

        $existing = self::getForPanelPort($portId);
        $existingPeerId = $existing ? self::getPeerPanelPortId($existing, $portId) : 0;
        if (!$existing) {
            self::assertPanelLinkAllowed($portId);
            self::assertPanelLinkAllowed($peerPortId);
        } elseif ($existingPeerId !== $peerPortId) {
            self::assertPanelLinkAllowed($peerPortId);
        }

        foreach ([$portId, $peerPortId] as $candidateId) {
            $port = new PluginPatchpanelPanelPort();
            $port->check($candidateId, UPDATE);
            $panel = new PluginPatchpanelPanel();
            $panel->check((int) $port->fields['plugin_patchpanel_panels_id'], UPDATE);
            if ((int) ($panel->fields['is_deleted'] ?? 0) !== 0) {
                throw new DomainException(
                    __('A panel-to-panel link cannot use a deleted patch panel.', 'patchpanel')
                );
            }
        }

        [$portIdA, $portIdB] = self::normalizePair($portId, $peerPortId);
        $length = trim((string) ($input['panel_link_length'] ?? ($existing['length'] ?? '')));
        if ($length !== '' && (!is_numeric($length) || (float) $length < 0)) {
            throw new InvalidArgumentException(__('The cable length must be zero or greater.', 'patchpanel'));
        }
        $color = trim((string) ($input['panel_link_cable_color'] ?? ($existing['cable_color'] ?? '')));
        if ($color !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            throw new InvalidArgumentException(__('The cable color is invalid.', 'patchpanel'));
        }
        $media = (string) ($input['panel_link_media_type'] ?? ($existing['media_type'] ?? 'other'));
        if (!array_key_exists($media, PluginPatchpanelPanelPort::getMediaOptions())) {
            throw new InvalidArgumentException(__('The link media type is invalid.', 'patchpanel'));
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $fields = [
            'panelports_id_a' => $portIdA,
            'panelports_id_b' => $portIdB,
            'cable_label' => trim((string) (
                $input['panel_link_cable_label'] ?? ($existing['cable_label'] ?? '')
            )) ?: null,
            'media_type' => $media,
            'cable_color' => $color !== '' ? strtolower($color) : null,
            'length' => $length !== '' ? (float) $length : null,
            'comment' => trim((string) (
                $input['panel_link_comment'] ?? ($existing['comment'] ?? '')
            )) ?: null,
            'is_active' => 1,
            'date_mod' => $now,
        ];

        $before = $existing ? self::getAuditSnapshot($existing) : [];
        if ($existing) {
            $saved = $DB->update(self::getTable(), $fields, ['id' => (int) $existing['id']]);
            $linkId = (int) $existing['id'];
        } else {
            $saved = $DB->insert(self::getTable(), $fields + ['date_creation' => $now]);
            $linkId = 0;
        }
        if (!$saved) {
            return false;
        }

        $savedLink = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => $linkId > 0
                ? ['id' => $linkId]
                : [
                    'panelports_id_a' => $portIdA,
                    'panelports_id_b' => $portIdB,
                ],
            'LIMIT' => 1,
        ])->current();
        if ($savedLink) {
            self::recordAudit(
                $existing ? 'panel_link_update' : 'panel_link_create',
                $before,
                self::getAuditSnapshot($savedLink)
            );
        }
        return true;
    }

    public static function deleteForPanelPort(int $portId): bool
    {
        global $DB;

        $links = iterator_to_array($DB->request([
            'FROM' => self::getTable(),
            'WHERE' => [
                'OR' => [
                    'panelports_id_a' => $portId,
                    'panelports_id_b' => $portId,
                ],
            ],
            'ORDER' => ['id ASC'],
        ]));
        if (!$links) {
            return true;
        }

        foreach ($links as $link) {
            $before = self::getAuditSnapshot($link);
            if (!$DB->delete(self::getTable(), ['id' => (int) $link['id']])) {
                return false;
            }
            self::recordAudit('panel_link_delete', $before, []);
        }
        return true;
    }

    private static function getAuditSnapshot(array $link): array
    {
        $snapshot = [
            'link_id' => (int) ($link['id'] ?? 0),
            'panelports_id_a' => (int) ($link['panelports_id_a'] ?? 0),
            'panelports_id_b' => (int) ($link['panelports_id_b'] ?? 0),
            'cable_label' => $link['cable_label'] ?? null,
            'media_type' => $link['media_type'] ?? null,
            'cable_color' => $link['cable_color'] ?? null,
            'length' => isset($link['length']) ? (float) $link['length'] : null,
            'comment' => $link['comment'] ?? null,
        ];
        foreach (['a', 'b'] as $side) {
            $portId = $snapshot['panelports_id_' . $side];
            $port = new PluginPatchpanelPanelPort();
            $port->getFromDB($portId);
            $panelId = (int) ($port->fields['plugin_patchpanel_panels_id'] ?? 0);
            $panel = new PluginPatchpanelPanel();
            $panel->getFromDB($panelId);
            $panelName = trim((string) ($panel->fields['name'] ?? ''));
            $snapshot['endpoint_' . $side] = [
                'panel_id' => $panelId,
                'panel_name' => $panelName !== ''
                    ? $panelName
                    : sprintf(__('Panel #%d', 'patchpanel'), $panelId),
                'port_id' => $portId,
                'port_number' => (int) ($port->fields['number'] ?? 0),
            ];
        }
        return $snapshot;
    }

    private static function recordAudit(string $action, array $before, array $after): void
    {
        $snapshot = $after ?: $before;
        foreach (['a', 'b'] as $side) {
            $endpoint = $snapshot['endpoint_' . $side] ?? [];
            $peer = $snapshot['endpoint_' . ($side === 'a' ? 'b' : 'a')] ?? [];
            $panelId = (int) ($endpoint['panel_id'] ?? 0);
            $portId = (int) ($endpoint['port_id'] ?? 0);
            if ($panelId <= 0 || $portId <= 0) {
                continue;
            }
            PluginPatchpanelAudit::record(
                $panelId,
                $portId,
                $action,
                'panel_port_form',
                sprintf(
                    __('Panel link with %1$s / port %2$d', 'patchpanel'),
                    (string) ($peer['panel_name'] ?? ''),
                    (int) ($peer['port_number'] ?? 0)
                ),
                $before,
                $after
            );
        }
    }
}
