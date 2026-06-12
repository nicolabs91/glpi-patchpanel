<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access is not allowed');
}

define('PLUGIN_PATCHPANEL_VERSION', '0.1.5');
define('PLUGIN_PATCHPANEL_MIN_GLPI', '11.0.0');
define('PLUGIN_PATCHPANEL_MAX_GLPI', '11.99.99');

function plugin_init_patchpanel(): void
{
    global $CFG_GLPI, $PLUGIN_HOOKS;

    Plugin::registerClass('PluginPatchpanelPanel');
    Plugin::registerClass('PluginPatchpanelPanelModel');
    Plugin::registerClass('PluginPatchpanelPanelPort');
    Plugin::registerClass('PluginPatchpanelPortEndpoint', [
        'addtabon' => array_values(array_unique(array_merge(
            ['Glpi\\Socket', NetworkPort::class],
            $CFG_GLPI['networkport_types'] ?? []
        ))),
    ]);
    Plugin::registerClass('PluginPatchpanelRoute');
    Plugin::registerClass('PluginPatchpanelRouteExplorer');
    Plugin::registerClass('PluginPatchpanelQuality');
    Plugin::registerClass('PluginPatchpanelCsvImport');
    Plugin::registerClass('PluginPatchpanelLabel');
    Plugin::registerClass('PluginPatchpanelAudit');
    Plugin::registerClass('PluginPatchpanelMigration');

    if (!in_array('PluginPatchpanelPanel', $CFG_GLPI['rackable_types'] ?? [], true)) {
        $CFG_GLPI['rackable_types'][] = 'PluginPatchpanelPanel';
    }

    $PLUGIN_HOOKS['csrf_compliant']['patchpanel'] = true;
    $PLUGIN_HOOKS['menu_toadd']['patchpanel'] = [
        'assets' => 'PluginPatchpanelPanel',
    ];
    $PLUGIN_HOOKS['add_css']['patchpanel'] = 'css/patchpanel.css';
}

function plugin_version_patchpanel(): array
{
    return [
        'name' => 'PatchPanel',
        'version' => PLUGIN_PATCHPANEL_VERSION,
        'author' => 'Nicolabs91',
        'license' => 'GPLv3+',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PATCHPANEL_MIN_GLPI,
                'max' => PLUGIN_PATCHPANEL_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_patchpanel_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_PATCHPANEL_MIN_GLPI, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_PATCHPANEL_MAX_GLPI, '<=');
}

function plugin_patchpanel_check_config(bool $verbose = false): bool
{
    return true;
}
