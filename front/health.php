<?php

include '../../../inc/includes.php';

Session::checkRight('networking', READ);

Html::header(__('PatchPanel health check', 'patchpanel'), $_SERVER['PHP_SELF'], 'assets');
PluginPatchpanelHealth::render();
Html::footer();
