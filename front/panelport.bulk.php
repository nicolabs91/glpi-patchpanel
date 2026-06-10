<?php

include '../../../inc/includes.php';

Session::checkRight('networking', UPDATE);

$panelId = (int) ($_POST['plugin_patchpanel_panels_id'] ?? 0);
$panel = new PluginPatchpanelPanel();
$panel->check($panelId, UPDATE);

try {
    $updated = PluginPatchpanelPanelPort::bulkUpdate($panelId, $_POST);
    Session::addMessageAfterRedirect(
        sprintf(__('%d panel ports updated.', 'patchpanel'), $updated)
    );
} catch (Throwable $e) {
    if (!$e instanceof InvalidArgumentException) {
        Toolbox::logInFile('php-errors', 'PatchPanel bulk update failed: ' . $e->getMessage() . "\n");
    }
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}

Html::redirect($panel->getFormURLWithID($panelId));
