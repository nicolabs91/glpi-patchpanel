<?php

include '../../../inc/includes.php';

Session::checkRight('networking', READ);
Html::header(PluginPatchpanelPanel::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'assets');
$legacy = PluginPatchpanelMigration::getLegacySummary();
if ($legacy['available'] && Session::haveRight('networking', UPDATE)) {
    echo "<div class='container-fluid mb-3'><a class='btn btn-outline-primary' href='" .
        htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/migration.php') . "'>";
    echo "<i class='ti ti-database-import'></i> " .
        htmlescape(__('Analyze legacy PatchPanel data', 'patchpanel'));
    echo '</a></div>';
}
Search::show(PluginPatchpanelPanel::class);
Html::footer();
