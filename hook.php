<?php

function plugin_patchpanel_install(): bool
{
    PluginPatchpanelMigration::installSchema();
    PluginPatchpanelPortEndpoint::synchronizeAllNativeNetworkPortLinks();
    return true;
}

function plugin_patchpanel_uninstall(): bool
{
    global $DB;

    // Old third-party PatchPanel tables are deliberately excluded. Removing
    // data outside this replacement plugin should stay an explicit DB action.
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

function plugin_patchpanel_cleanup_socket_device_selection_when_port_is_empty(CommonDBTM $item): void
{
    PluginPatchpanelPortEndpoint::cleanupSocketDeviceSelectionWhenPortIsEmpty($item);
}

function plugin_patchpanel_sync_socket_native_network_link(CommonDBTM $item): void
{
    PluginPatchpanelPortEndpoint::cleanupSocketDeviceSelectionWhenPortIsEmpty($item);
    PluginPatchpanelPortEndpoint::synchronizeNativeNetworkPortLinksForSocket($item);
}

function plugin_patchpanel_sync_front_endpoint_after_native_connect(CommonDBTM $item): void
{
    PluginPatchpanelPortEndpoint::syncFrontEndpointAfterNativeNetworkPortConnect($item);
}

function plugin_patchpanel_cleanup_front_endpoint_after_native_disconnect(CommonDBTM $item): void
{
    PluginPatchpanelPortEndpoint::cleanupFrontEndpointAfterNativeNetworkPortDisconnect($item);
}
