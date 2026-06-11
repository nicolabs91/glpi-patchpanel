<?php

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkRight('networking', READ);

$panelId = (int) ($_GET['panel_id'] ?? 0);
$panel = new PluginPatchpanelPanel();
$panel->check($panelId, READ);

$max = (int) $panel->fields['port_count'];
$from = max(1, (int) ($_GET['from_port'] ?? 1));
$to = max($from, (int) ($_GET['to_port'] ?? $max));
$from = min($max, $from);
$to = min($max, $to);
$ports = PluginPatchpanelLabel::getPorts($panel, $from, $to);

$location = '';
if ((int) ($panel->fields['locations_id'] ?? 0) > 0) {
    $location = Dropdown::getDropdownName(
        Location::getTable(),
        (int) $panel->fields['locations_id']
    );
}
$rack = PluginPatchpanelLabel::getRackName($panelId);

Html::header(__('PatchPanel QR labels', 'patchpanel'), $_SERVER['PHP_SELF'], 'assets');
echo "<div class='container-fluid'>";
echo "<div class='patchpanel-label-controls d-flex align-items-center gap-2 mb-3'>";
echo '<div><h1 class="h2 mb-1">' .
    htmlescape(__('Panel port QR labels', 'patchpanel')) . '</h1><p class="text-muted mb-0">' .
    htmlescape($panel->fields['name']) . '</p></div>';
echo "<button class='btn btn-primary ms-auto' type='button' onclick='window.print()'>";
echo "<i class='ti ti-printer'></i> " . htmlescape(__('Print')) . '</button>';
echo "<a class='btn btn-outline-secondary' href='" .
    htmlescape($panel->getFormURLWithID($panelId)) . "'>" .
    htmlescape(__('Back')) . '</a></div>';

echo "<section class='card patchpanel-label-controls mb-3'><div class='card-body'>";
echo "<form class='row g-3 align-items-end' method='get'>";
echo Html::hidden('panel_id', ['value' => $panelId]);
echo "<div class='col-sm-3'><label class='form-label'>" .
    htmlescape(__('From port', 'patchpanel')) . '</label>';
echo Html::input('from_port', [
    'type' => 'number',
    'value' => $from,
    'min' => 1,
    'max' => $max,
]);
echo '</div><div class="col-sm-3"><label class="form-label">' .
    htmlescape(__('To port', 'patchpanel')) . '</label>';
echo Html::input('to_port', [
    'type' => 'number',
    'value' => $to,
    'min' => 1,
    'max' => $max,
]);
echo '</div><div class="col-sm-3"><button class="btn btn-outline-primary" type="submit">' .
    htmlescape(__('Generate labels', 'patchpanel')) . '</button></div>';
Html::closeForm();
echo '</div></section>';

echo "<div class='patchpanel-label-sheet'>";
foreach ($ports as $port) {
    $url = PluginPatchpanelLabel::getPortUrl((int) $port['id']);
    $details = array_filter([$location, $rack]);
    echo "<article class='patchpanel-label'>";
    echo "<img alt='" . htmlescape(__('QR code for panel port', 'patchpanel')) .
        "' src='" . htmlescape(PluginPatchpanelLabel::getQrDataUri($url)) . "'>";
    echo '<div><div class="fw-bold">' . htmlescape($panel->fields['name']) . '</div>';
    echo '<div class="patchpanel-label-port">' .
        sprintf(htmlescape(__('Port %d', 'patchpanel')), (int) $port['number']) .
        '</div><div>' . htmlescape((string) $port['label']) . '</div>';
    if ($details) {
        echo '<div class="small text-muted">' .
            htmlescape(implode(' / ', $details)) . '</div>';
    }
    echo '<div class="small text-muted">' .
        htmlescape($url) . '</div></div></article>';
}
echo '</div></div>';
Html::footer();
