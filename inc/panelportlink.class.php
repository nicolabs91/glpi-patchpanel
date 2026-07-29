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
        $media = (string) ($input['panel_link_media_type'] ?? ($existing['media_type'] ?? 'fiber'));
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

        if ($existing) {
            return $DB->update(self::getTable(), $fields, ['id' => (int) $existing['id']]);
        }
        return $DB->insert(self::getTable(), $fields + ['date_creation' => $now]);
    }

    public static function deleteForPanelPort(int $portId): bool
    {
        global $DB;

        $link = self::getForPanelPort($portId, false);
        if (!$link) {
            return true;
        }
        return $DB->delete(self::getTable(), ['id' => (int) $link['id']]);
    }
}
