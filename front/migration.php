<?php

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkRight('networking', UPDATE);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_selected'])) {
    if (empty($_POST['confirm_import'])) {
        Session::addMessageAfterRedirect(
            __('Confirm that you reviewed the migration preview.', 'patchpanel'),
            false,
            ERROR
        );
    } else {
        try {
            $result = PluginPatchpanelMigration::importLegacyPanels($_POST['legacy_panels'] ?? []);
            Session::addMessageAfterRedirect(sprintf(
                __('Imported %1$d panels and %2$d ports. %3$d ports are partial and %4$d contain conflicts.', 'patchpanel'),
                $result['panels'],
                $result['ports'],
                $result['partial_ports'],
                $result['conflict_ports']
            ));
        } catch (Throwable $e) {
            Toolbox::logInFile('php-errors', 'PatchPanel migration failed: ' . $e->getMessage() . "\n");
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
    }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/migration.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rollback_batch'])) {
    try {
        $count = PluginPatchpanelMigration::rollbackBatch((string) ($_POST['batch_uuid'] ?? ''));
        Session::addMessageAfterRedirect(sprintf(
            __('Rolled back %d imported panels. Legacy data was not changed.', 'patchpanel'),
            $count
        ));
    } catch (Throwable $e) {
        Toolbox::logInFile('php-errors', 'PatchPanel migration rollback failed: ' . $e->getMessage() . "\n");
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/migration.php');
}

function plugin_patchpanel_migration_badge(string $status): string
{
    $classes = [
        'ready' => 'bg-success-lt text-success',
        'empty' => 'bg-secondary-lt text-secondary',
        'partial' => 'bg-warning-lt text-warning',
        'conflict' => 'bg-danger-lt text-danger',
        'invalid' => 'bg-danger-lt text-danger',
    ];
    $labels = [
        'ready' => __('Ready', 'patchpanel'),
        'empty' => __('Empty', 'patchpanel'),
        'partial' => __('Partial', 'patchpanel'),
        'conflict' => __('Conflict', 'patchpanel'),
        'invalid' => __('Invalid', 'patchpanel'),
    ];
    return "<span class='badge " . ($classes[$status] ?? 'bg-secondary-lt') . "'>" .
        htmlescape($labels[$status] ?? $status) . '</span>';
}

$analysis = PluginPatchpanelMigration::analyzeLegacy();
$batches = PluginPatchpanelMigration::getImportedBatches();
$migrationUrl = $CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/migration.php';

Html::header(__('PatchPanel migration preview', 'patchpanel'), $_SERVER['PHP_SELF'], 'assets');
echo "<div class='container-fluid patchpanel-migration'>";
echo "<div class='d-flex align-items-center justify-content-between mb-3'>";
echo '<div><h1 class="h2 mb-1">' . htmlescape(__('Legacy migration preview', 'patchpanel')) . '</h1>';
echo '<p class="text-muted mb-0">' .
    htmlescape(__('This analysis is read-only. Import creates new records and never deletes or changes legacy tables.', 'patchpanel')) .
    '</p></div>';
echo "<a class='btn btn-outline-secondary' href='" .
    htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panel.php') . "'>" .
    htmlescape(__('Back to patch panels', 'patchpanel')) . '</a></div>';

if (!$analysis['available']) {
    echo "<div class='alert alert-info'>" .
        htmlescape(__('No legacy PatchPanel tables were found.', 'patchpanel')) .
        '</div></div>';
    Html::footer();
    exit;
}

echo "<div class='row g-3 mb-4'>";
foreach ([
    'panels' => __('Legacy panels', 'patchpanel'),
    'ports' => __('Legacy ports', 'patchpanel'),
    'ready' => __('Ready ports', 'patchpanel'),
    'empty' => __('Empty ports', 'patchpanel'),
    'partial' => __('Partial ports', 'patchpanel'),
    'conflict' => __('Conflicting ports', 'patchpanel'),
] as $key => $label) {
    echo "<div class='col-6 col-md-2'><div class='card h-100'><div class='card-body'>";
    echo "<div class='text-muted small'>" . htmlescape($label) . '</div>';
    echo "<div class='display-6'>" . (int) $analysis['summary'][$key] . '</div>';
    echo '</div></div></div>';
}
echo '</div>';

echo "<div class='alert alert-warning'>";
echo '<strong>' . htmlescape(__('Conflict policy:', 'patchpanel')) . '</strong> ';
echo htmlescape(
    __('A panel and all of its port labels are imported, but a duplicate, missing, or ambiguous endpoint is skipped. The valid opposite side is preserved, leaving the new port visibly incomplete for manual resolution.', 'patchpanel')
);
echo '</div>';

echo "<form method='post' action='" . htmlescape($migrationUrl) . "'>";
echo "<div class='card mb-4'><div class='card-header'><h2 class='card-title mb-0'>" .
    htmlescape(__('Panels to import', 'patchpanel')) . '</h2></div>';
echo "<div class='card-body p-0'><div class='table-responsive'><table class='table table-vcenter mb-0'>";
echo '<thead><tr><th></th><th>' . htmlescape(__('Legacy panel', 'patchpanel')) . '</th><th>' .
    htmlescape(__('Ports')) . '</th><th>' . htmlescape(__('Ready', 'patchpanel')) . '</th><th>' .
    htmlescape(__('Empty', 'patchpanel')) . '</th><th>' . htmlescape(__('Partial', 'patchpanel')) . '</th><th>' .
    htmlescape(__('Conflict', 'patchpanel')) . '</th><th>' . htmlescape(__('Details')) . '</th></tr></thead><tbody>';

foreach ($analysis['panels'] as $panel) {
    echo '<tr><td>';
    if ($panel['is_imported']) {
        echo "<i class='ti ti-circle-check text-success' title='" .
            htmlescape(__('Already imported', 'patchpanel')) . "'></i>";
    } else {
        echo "<input class='form-check-input' type='checkbox' name='legacy_panels[]' value='" .
            (int) $panel['id'] . "' checked>";
    }
    echo '</td><td><strong>' . htmlescape($panel['name']) . '</strong><div class="text-muted">#' .
        (int) $panel['id'];
    if ($panel['is_imported']) {
        echo ' → ' . sprintf(
            '<a href="%s">%s #%d</a>',
            htmlescape(PluginPatchpanelPanel::getFormURLWithID($panel['target_id'])),
            htmlescape(__('New panel', 'patchpanel')),
            $panel['target_id']
        );
    }
    echo '</div></td><td>' . count($panel['ports']) . ' / ' . (int) $panel['port_count'] . '</td>';
    echo '<td>' . (int) $panel['counts']['ready'] . '</td>';
    echo '<td>' . (int) $panel['counts']['empty'] . '</td>';
    echo '<td>' . (int) ($panel['counts']['partial'] + $panel['counts']['invalid']) . '</td>';
    echo '<td>' . (int) $panel['counts']['conflict'] . '</td><td>';
    echo "<details><summary class='btn btn-sm btn-outline-secondary'>" .
        htmlescape(__('Show port preview', 'patchpanel')) . '</summary>';
    echo "<div class='table-responsive mt-2'><table class='table table-sm table-bordered'>";
    echo '<thead><tr><th>' . htmlescape(__('Port')) . '</th><th>' . htmlescape(__('Status')) .
        '</th><th>' . htmlescape(__('Rear proposal', 'patchpanel')) . '</th><th>' .
        htmlescape(__('Front proposal', 'patchpanel')) . '</th><th>' .
        htmlescape(__('Notes')) . '</th></tr></thead><tbody>';
    foreach ($panel['ports'] as $port) {
        echo '<tr><td>' . (int) $port['number'] . '<br><span class="text-muted">' .
            htmlescape($port['label']) . '</span></td><td>' .
            plugin_patchpanel_migration_badge($port['status']) . '</td>';
        foreach (['rear', 'front'] as $side) {
            $endpoint = $port[$side];
            echo '<td>' . plugin_patchpanel_migration_badge($endpoint['status']) . '<br>';
            echo $endpoint['candidate_id'] > 0
                ? htmlescape($endpoint['label'] ?: sprintf('#%d', $endpoint['candidate_id']))
                : '<span class="text-muted">' . htmlescape(__('None')) . '</span>';
            echo '</td>';
        }
        echo '<td>' . htmlescape(implode(' ', $port['issues'])) . '</td></tr>';
    }
    echo '</tbody></table></div></details></td></tr>';
}
echo '</tbody></table></div></div>';
echo "<div class='card-footer'>";
echo "<label class='form-check mb-3'><input class='form-check-input' type='checkbox' name='confirm_import' value='1' required>";
echo "<span class='form-check-label'>" .
    htmlescape(__('I reviewed the preview and understand that conflict endpoints will be skipped.', 'patchpanel')) .
    '</span></label>';
echo "<button class='btn btn-primary' type='submit' name='import_selected' value='1'>";
echo "<i class='ti ti-database-import'></i> " . htmlescape(__('Import selected panels', 'patchpanel'));
echo '</button>';
echo '</div></div>';
Html::closeForm();

echo "<div class='card mb-4'><div class='card-header'><h2 class='card-title mb-0'>" .
    htmlescape(__('Rollback batches', 'patchpanel')) . '</h2></div><div class="card-body">';
if (!$batches) {
    echo "<p class='text-muted mb-0'>" . htmlescape(__('No active migration batch exists.', 'patchpanel')) . '</p>';
} else {
    echo "<div class='table-responsive'><table class='table table-vcenter'><thead><tr><th>" .
        htmlescape(__('Batch')) . '</th><th>' . htmlescape(__('Date')) . '</th><th>' .
        htmlescape(__('Panels')) . '</th><th>' . htmlescape(__('Ports')) .
        '</th><th></th></tr></thead><tbody>';
    foreach ($batches as $batch) {
        echo '<tr><td><code>' . htmlescape(substr($batch['batch_uuid'], 0, 12)) .
            '</code></td><td>' . htmlescape((string) $batch['date_mod']) . '</td><td>' .
            (int) $batch['panels'] . '</td><td>' . (int) $batch['ports'] . '</td><td>';
        echo "<form method='post' action='" . htmlescape($migrationUrl) . "'>";
        echo Html::hidden('batch_uuid', ['value' => $batch['batch_uuid']]);
        echo "<button class='btn btn-sm btn-outline-danger' type='submit' name='rollback_batch' value='1' " .
            "onclick=\"return confirm('" . htmlescape(__('Rollback this import batch?', 'patchpanel')) . "');\">";
        echo "<i class='ti ti-arrow-back-up'></i> " . htmlescape(__('Rollback import', 'patchpanel'));
        echo '</button>';
        Html::closeForm();
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div></div>';
Html::footer();
