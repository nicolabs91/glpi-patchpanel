<?php

class PluginPatchpanelPanelModel extends CommonDropdown
{
    public static $rightname = 'networking';

    public static function getTypeName($nb = 0): string
    {
        return _n('Patch panel model', 'Patch panel models', $nb, 'patchpanel');
    }
}
