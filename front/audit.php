<?php

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkRight('networking', READ);

$panelId = (int) ($_GET['panel_id'] ?? 0);
$panel = new PluginPatchpanelPanel();
$panel->check($panelId, READ);
$events = PluginPatchpanelAudit::getForPanel($panelId);

Html::header(__('PatchPanel audit history', 'patchpanel'), $_SERVER['PHP_SELF'], 'assets');
echo "<div class='container-fluid'>";
echo "<div class='d-flex align-items-center gap-2 mb-3'><div><h1 class='h2 mb-1'>" .
    htmlescape(__('Panel audit history', 'patchpanel')) . '</h1><p class="text-muted mb-0">' .
    htmlescape($panel->fields['name']) . '</p></div>';
echo "<a class='btn btn-outline-secondary ms-auto' href='" .
    htmlescape($panel->getFormURLWithID($panelId)) . "'>" .
    htmlescape(__('Back')) . '</a></div>';

echo "<section class='card'><div class='table-responsive'>";
echo "<table class='table card-table'><thead><tr><th>" . htmlescape(__('Date')) .
    '</th><th>' . htmlescape(__('User')) . '</th><th>' .
    htmlescape(__('Source', 'patchpanel')) . '</th><th>' .
    htmlescape(__('Action')) . '</th><th>' .
    htmlescape(__('Change', 'patchpanel')) . '</th><th>' .
    htmlescape(__('Details')) . '</th></tr></thead><tbody>';
if (!$events) {
    echo "<tr><td colspan='6' class='text-muted'>" .
        htmlescape(__('No audited changes exist for this panel.', 'patchpanel')) .
        '</td></tr>';
}
foreach ($events as $event) {
    $before = PluginPatchpanelAudit::formatSnapshot($event['before_json']);
    $after = PluginPatchpanelAudit::formatSnapshot($event['after_json']);
    echo '<tr><td>' . htmlescape(Html::convDateTime($event['date_creation'])) .
        '</td><td>' . htmlescape((string) ($event['user_name'] ?: '#' . $event['users_id'])) .
        '</td><td><code>' . htmlescape($event['source']) .
        '</code></td><td>' . htmlescape($event['action']) .
        '</td><td>' . htmlescape($event['summary']) . '</td><td>';
    echo '<details><summary>' . htmlescape(__('Before / after', 'patchpanel')) .
        '</summary><div class="row g-2 mt-1"><div class="col-lg-6"><strong>' .
        htmlescape(__('Before')) . '</strong><pre class="small">' .
        htmlescape($before ?: '-') . '</pre></div><div class="col-lg-6"><strong>' .
        htmlescape(__('After')) . '</strong><pre class="small">' .
        htmlescape($after ?: '-') . '</pre></div></div></details></td></tr>';
}
echo '</tbody></table></div></section></div>';
Html::footer();
