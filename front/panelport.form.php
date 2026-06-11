<?php

include '../../../inc/includes.php';

Session::checkRight('networking', READ);
$port = new PluginPatchpanelPanelPort();

if (isset($_POST['update'])) {
    $port->check($_POST['id'], UPDATE);
    $portInput = $_POST;
    foreach ([
        'rear_items_id',
        'rear_cable_color',
        'front_items_id',
        'front_cable_color',
        'front_cable_label',
        'front_cables_id',
    ] as $field) {
        unset($portInput[$field]);
    }

    global $DB;
    $DB->beginTransaction();
    try {
        $before = PluginPatchpanelCsvImport::snapshot((int) $_POST['id']);
        if (!$port->update($portInput)) {
            throw new RuntimeException('Port update failed');
        }
        if (!PluginPatchpanelPortEndpoint::saveForPort((int) $_POST['id'], $_POST, false)) {
            throw new RuntimeException('Endpoint update failed');
        }
        PluginPatchpanelAudit::record(
            (int) $port->fields['plugin_patchpanel_panels_id'],
            (int) $_POST['id'],
            'update',
            'manual',
            sprintf(
                __('Updated panel port %d', 'patchpanel'),
                (int) $port->fields['number']
            ),
            $before,
            PluginPatchpanelCsvImport::snapshot((int) $_POST['id'])
        );
        $DB->commit();
        Session::addMessageAfterRedirect(__('Patch panel port saved', 'patchpanel'));
    } catch (Throwable $e) {
        $DB->rollBack();
        Toolbox::logInFile(
            'php-errors',
            'PatchPanel port form failed: ' . $e->getMessage() . "\n"
        );
        Session::addMessageAfterRedirect(__('No changes were saved.', 'patchpanel'), false, ERROR);
    }
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
$port->check($id, READ);
Html::header(PluginPatchpanelPanelPort::getTypeName(1), $_SERVER['PHP_SELF'], 'assets');
$port->display(['id' => $id]);
Html::footer();
