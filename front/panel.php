<?php

include '../../../inc/includes.php';

Session::checkRight('networking', READ);
Html::header(PluginPatchpanelPanel::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'assets');
Search::show(PluginPatchpanelPanel::class);
Html::footer();
