<?php

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkRight('config', READ);

Html::header(__('PatchPanel settings', 'patchpanel'), $_SERVER['PHP_SELF'], 'config');
echo "<div class='container-fluid patchpanel-settings'>";
echo "<div class='mb-3'><h1 class='h2 mb-1'>" . htmlescape(__('PatchPanel settings', 'patchpanel')) . '</h1>';
echo "<p class='text-muted mb-0'>" .
    htmlescape(__('Administrative PatchPanel tools are kept here so the daily cabling views stay focused.', 'patchpanel')) .
    '</p></div>';

echo "<section class='card mb-3'><div class='card-body'>";
echo "<h2 class='h4'>" . htmlescape(__('Maintenance tools', 'patchpanel')) . '</h2>';
echo "<div class='d-flex flex-wrap gap-2 mt-3'>";
echo "<a class='btn btn-outline-secondary' href='" .
    htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/health.php') . "'>";
echo "<i class='ti ti-heart-check'></i> " . htmlescape(__('Health check', 'patchpanel')) . '</a>';
if (Session::haveRight('networking', UPDATE)) {
    echo "<a class='btn btn-outline-secondary' href='" .
        htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/csvimport.php') . "'>";
    echo "<i class='ti ti-file-spreadsheet'></i> " . htmlescape(__('CSV import', 'patchpanel')) . '</a>';
}
echo '</div></div></section>';
echo '</div>';
Html::footer();
