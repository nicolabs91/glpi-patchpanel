<?php

final class PluginPatchpanelAudit
{
    public static function record(
        int $panelId,
        int $portId,
        string $action,
        string $source,
        string $summary,
        array $before,
        array $after
    ): void {
        global $DB;

        $DB->insert('glpi_plugin_patchpanel_audits', [
            'event_uuid' => bin2hex(random_bytes(16)),
            'plugin_patchpanel_panels_id' => $panelId,
            'plugin_patchpanel_panelports_id' => $portId,
            'users_id' => (int) Session::getLoginUserID(),
            'action' => mb_substr($action, 0, 32),
            'source' => mb_substr($source, 0, 32),
            'summary' => mb_substr($summary, 0, 255),
            'before_json' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_json' => $after ? json_encode($after, JSON_THROW_ON_ERROR) : null,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public static function getForPanel(int $panelId): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'SELECT' => [
                'glpi_plugin_patchpanel_audits.*',
                'glpi_users.name AS user_name',
            ],
            'FROM' => 'glpi_plugin_patchpanel_audits',
            'LEFT JOIN' => [
                User::getTable() => [
                    'FKEY' => [
                        'glpi_plugin_patchpanel_audits' => 'users_id',
                        User::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE' => ['plugin_patchpanel_panels_id' => $panelId],
            'ORDER' => ['date_creation DESC', 'id DESC'],
        ]));
    }

    public static function formatSnapshot(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '';
        }
        return json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '';
    }
}
