<?php

include '../../../inc/includes.php';

Session::checkRight('networking', READ);

$status = (string) ($_GET['status'] ?? '');
$query = (string) ($_GET['q'] ?? '');

Html::header(__('Cabling quality', 'patchpanel'), $_SERVER['PHP_SELF'], 'assets');
PluginPatchpanelQuality::render($status, $query);
Html::footer();
