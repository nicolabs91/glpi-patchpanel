<?php

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkRight('networking', READ);
Html::header(PluginPatchpanelPanel::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'assets');
echo "<div class='container-fluid mb-3 d-flex flex-wrap gap-2'>";
echo "<a class='btn btn-outline-primary' href='" .
    htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/quality.php') . "'>";
echo "<i class='ti ti-list-check'></i> " .
    htmlescape(__('Cabling quality and free ports', 'patchpanel'));
echo '</a>';
echo "<a class='btn btn-outline-primary' href='" .
    htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/routes.php') . "'>";
echo "<i class='ti ti-route'></i> " .
    htmlescape(__('Route search and impact', 'patchpanel'));
echo '</a>';
if (Session::haveRight('networking', UPDATE)) {
    echo "<a class='btn btn-outline-primary' href='" .
        htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/csvimport.php') . "'>";
    echo "<i class='ti ti-file-spreadsheet'></i> " .
        htmlescape(__('CSV import', 'patchpanel'));
    echo '</a>';
}
$legacy = PluginPatchpanelMigration::getLegacySummary();
if ($legacy['available'] && Session::haveRight('networking', UPDATE)) {
    echo "<a class='btn btn-outline-primary' href='" .
        htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/migration.php') . "'>";
    echo "<i class='ti ti-database-import'></i> " .
        htmlescape(__('Analyze legacy PatchPanel data', 'patchpanel'));
    echo '</a>';
}
echo '</div>';
Search::show(PluginPatchpanelPanel::class);
Html::footer();
