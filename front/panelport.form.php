<?php

include '../../../inc/includes.php';

Session::checkRight('networking', READ);
$port = new PluginPatchpanelPanelPort();

if (isset($_POST['disconnect_endpoint'])) {
    $port->check($_POST['id'], UPDATE);
    global $DB;
    $side = (string) ($_POST['side'] ?? '');

    $DB->beginTransaction();
    try {
        $before = PluginPatchpanelCsvImport::snapshot((int) $_POST['id']);
        PluginPatchpanelPortEndpoint::disconnectSide((int) $_POST['id'], $side);
        $sideLabel = $side === PluginPatchpanelPortEndpoint::REAR
            ? __('rear side', 'patchpanel')
            : __('front side', 'patchpanel');
        PluginPatchpanelAudit::record(
            (int) $port->fields['plugin_patchpanel_panels_id'],
            (int) $_POST['id'],
            'disconnect',
            'manual',
            sprintf(
                __('Disconnected %1$s of panel port %2$d', 'patchpanel'),
                $sideLabel,
                (int) $port->fields['number']
            ),
            $before,
            PluginPatchpanelCsvImport::snapshot((int) $_POST['id'])
        );
        $DB->commit();
        Session::addMessageAfterRedirect(__('Patch panel endpoint disconnected', 'patchpanel'));
    } catch (Throwable $e) {
        $DB->rollBack();
        Toolbox::logInFile(
            'php-errors',
            'PatchPanel endpoint disconnect failed: ' . $e->getMessage() . "\n"
        );
        Session::addMessageAfterRedirect(__('No endpoint was disconnected.', 'patchpanel'), false, ERROR);
    }
    Html::back();
}

if (isset($_POST['disconnect_socket_device'])) {
    $port->check($_POST['id'], UPDATE);
    global $DB;

    $DB->beginTransaction();
    try {
        $before = PluginPatchpanelCsvImport::snapshot((int) $_POST['id']);
        PluginPatchpanelPortEndpoint::disconnectSocketDevice((int) ($_POST['socket_id'] ?? 0));
        PluginPatchpanelAudit::record(
            (int) $port->fields['plugin_patchpanel_panels_id'],
            (int) $_POST['id'],
            'disconnect',
            'manual',
            sprintf(
                __('Cleaned up endpoint device selection for panel port %d', 'patchpanel'),
                (int) $port->fields['number']
            ),
            $before,
            PluginPatchpanelCsvImport::snapshot((int) $_POST['id'])
        );
        $DB->commit();
        Session::addMessageAfterRedirect(__('Endpoint device selection cleaned up', 'patchpanel'));
    } catch (Throwable $e) {
        $DB->rollBack();
        Toolbox::logInFile(
            'php-errors',
            'PatchPanel socket device disconnect failed: ' . $e->getMessage() . "\n"
        );
        Session::addMessageAfterRedirect(__('No endpoint device was disconnected.', 'patchpanel'), false, ERROR);
    }
    Html::back();
}

if (isset($_POST['update'])) {
    $port->check($_POST['id'], UPDATE);
    $portInput = $_POST;
    foreach ([
        'rear_items_id',
        'rear_cable_color',
        'front_items_id',
        'front_cable_color',
        'front_cables_id',
    ] as $field) {
        unset($portInput[$field]);
    }
    $endpointInput = $_POST;
    unset($endpointInput['front_cables_id']);

    global $DB;
    $DB->beginTransaction();
    try {
        $before = PluginPatchpanelCsvImport::snapshot((int) $_POST['id']);
        if (!$port->update($portInput)) {
            throw new RuntimeException('Port update failed');
        }
        if (!PluginPatchpanelPortEndpoint::saveForPort((int) $_POST['id'], $endpointInput, false)) {
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
