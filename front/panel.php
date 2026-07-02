<?php

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkRight('networking', READ);
Html::header(PluginPatchpanelPanel::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'assets');
echo "<div class='patchpanel-list-actions container-fluid mb-3 d-flex flex-wrap gap-2'>";
if (PluginPatchpanelPanel::canCreate()) {
    echo "<a class='btn btn-primary' href='" .
        htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panel.form.php?id=-1') . "'>";
    echo "<i class='ti ti-plus'></i> " .
        htmlescape(__('Add patch panel', 'patchpanel'));
    echo '</a>';
}
echo "<a class='btn btn-secondary' href='" .
    htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/routes.php') . "'>";
echo "<i class='ti ti-route'></i> " .
    htmlescape(__('Route search and impact', 'patchpanel'));
echo '</a>';
echo '</div>';
Search::show(PluginPatchpanelPanel::class);
Html::footer();
