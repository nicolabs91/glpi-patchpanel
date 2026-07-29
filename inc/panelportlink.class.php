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
}
