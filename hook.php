<?php

function plugin_patchpanel_install(): bool
{
    PluginPatchpanelMigration::installSchema();
    return true;
}

function plugin_patchpanel_uninstall(): bool
{
    global $DB;

    // Legacy tables are deliberately excluded. They remain available for the
    // migration preview and can only be removed by an explicit future action.
    foreach ([
        'glpi_plugin_patchpanel_portendpoints',
        'glpi_plugin_patchpanel_panelports',
        'glpi_plugin_patchpanel_panels',
        'glpi_plugin_patchpanel_panelmodels',
        'glpi_plugin_patchpanel_migrations',
        'glpi_plugin_patchpanel_audits',
        'glpi_plugin_patchpanel_importchanges',
        'glpi_plugin_patchpanel_importbatches',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    return true;
}

function plugin_patchpanel_getDatabaseRelations(): array
{
    return [
        'glpi_entities' => [
            'glpi_plugin_patchpanel_panels' => 'entities_id',
        ],
        'glpi_locations' => [
            'glpi_plugin_patchpanel_panels' => 'locations_id',
        ],
        'glpi_plugin_patchpanel_panels' => [
            'glpi_plugin_patchpanel_panelports' => 'plugin_patchpanel_panels_id',
        ],
        'glpi_plugin_patchpanel_panelports' => [
            'glpi_plugin_patchpanel_portendpoints' => 'plugin_patchpanel_panelports_id',
        ],
    ];
}

function plugin_patchpanel_getDropdown(): array
{
    return [
        'PluginPatchpanelPanelModel' => PluginPatchpanelPanelModel::getTypeName(
            Session::getPluralNumber()
        ),
    ];
}
