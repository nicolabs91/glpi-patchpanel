<?php

use Glpi\Event;

include '../../../inc/includes.php';

Session::checkRight('networking', READ);
$panel = new PluginPatchpanelPanel();

if (isset($_POST['add'])) {
    $panel->check(-1, CREATE, $_POST);
    if ($newId = $panel->add($_POST)) {
        Event::log($newId, 'patchpanel', 4, 'inventory', sprintf(
            __('%1$s adds the item %2$s'),
            $_SESSION['glpiname'],
            $_POST['name']
        ));
        Html::redirect($panel->getFormURLWithID($newId));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $panel->check($_POST['id'], UPDATE);
    $panel->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    $panel->check($_POST['id'], DELETE);
    $panel->delete($_POST);
    $panel->redirectToList();
} elseif (isset($_POST['restore'])) {
    $panel->check($_POST['id'], DELETE);
    $panel->restore($_POST);
    $panel->redirectToList();
} elseif (isset($_POST['purge'])) {
    $panel->check($_POST['id'], PURGE);
    $panel->delete($_POST, true);
    $panel->redirectToList();
}

$id = (int) ($_GET['id'] ?? -1);
Html::header(PluginPatchpanelPanel::getTypeName(1), $_SERVER['PHP_SELF'], 'assets');
$panel->display(['id' => $id]);
Html::footer();
