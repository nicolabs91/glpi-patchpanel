<?php

include '../../../inc/includes.php';

Session::checkRight('networking', READ);
$model = new PluginPatchpanelPanelModel();

if (isset($_POST['add'])) {
    $model->check(-1, CREATE, $_POST);
    if ($id = $model->add($_POST)) {
        Html::redirect($model->getFormURLWithID($id));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $model->check($_POST['id'], UPDATE);
    $model->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $model->check($_POST['id'], PURGE);
    $model->delete($_POST, true);
    $model->redirectToList();
}

$id = (int) ($_GET['id'] ?? -1);
Html::header(PluginPatchpanelPanelModel::getTypeName(1), $_SERVER['PHP_SELF'], 'config');
$model->display(['id' => $id]);
Html::footer();
