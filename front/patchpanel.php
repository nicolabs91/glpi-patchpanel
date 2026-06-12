<?php

include '../../../inc/includes.php';

global $CFG_GLPI;

// Keep the legacy/default plugin entry point working after upgrades and stale menu caches.
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panel.php');
