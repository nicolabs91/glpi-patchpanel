<?php

include '../../../inc/includes.php';

Session::checkRight('networking', READ);
Html::header(PluginPatchpanelPanelModel::getTypeName(Session::getPluralNumber()), $_SERVER['PHP_SELF'], 'config');
Search::show(PluginPatchpanelPanelModel::class);
Html::footer();
