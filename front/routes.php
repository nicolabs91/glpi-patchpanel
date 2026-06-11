<?php

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkRight('networking', READ);

$query = (string) ($_GET['q'] ?? '');
$impactType = (string) ($_GET['impact_type'] ?? '');
$impactId = (int) ($_GET['impact_id'] ?? 0);

Html::header(__('Physical route explorer', 'patchpanel'), $_SERVER['PHP_SELF'], 'assets');
PluginPatchpanelRouteExplorer::render($query, $impactType, $impactId);
Html::footer();
