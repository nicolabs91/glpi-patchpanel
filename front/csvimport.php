<?php

include '../../../inc/includes.php';

global $CFG_GLPI;

Session::checkRight('networking', UPDATE);

$csv = '';
$analysis = null;
if (isset($_POST['preview_csv'])) {
    if (
        !isset($_FILES['csv_file'])
        || (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file($_FILES['csv_file']['tmp_name'])
    ) {
        Session::addMessageAfterRedirect(__('Choose a readable CSV file.', 'patchpanel'), false, ERROR);
    } else {
        $csv = (string) file_get_contents($_FILES['csv_file']['tmp_name'], false, null, 0, PluginPatchpanelCsvImport::MAX_BYTES + 1);
        $analysis = PluginPatchpanelCsvImport::analyze($csv);
    }
} elseif (isset($_POST['apply_csv'])) {
    $csv = base64_decode((string) ($_POST['csv_payload'] ?? ''), true) ?: '';
    try {
        if (empty($_POST['confirm_import'])) {
            throw new InvalidArgumentException(__('Confirm that you reviewed the CSV preview.', 'patchpanel'));
        }
        $result = PluginPatchpanelCsvImport::apply($csv);
        Session::addMessageAfterRedirect(sprintf(
            __('Imported %1$d CSV rows in rollback batch %2$s.', 'patchpanel'),
            $result['row_count'],
            $result['batch_uuid']
        ));
    } catch (Throwable $e) {
        Toolbox::logInFile('php-errors', 'PatchPanel CSV import failed: ' . $e->getMessage() . "\n");
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/csvimport.php');
} elseif (isset($_POST['rollback_batch'])) {
    try {
        $count = PluginPatchpanelCsvImport::rollback((string) ($_POST['batch_uuid'] ?? ''));
        Session::addMessageAfterRedirect(sprintf(
            __('Rolled back %d CSV changes.', 'patchpanel'),
            $count
        ));
    } catch (DomainException | InvalidArgumentException $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    } catch (Throwable $e) {
        Toolbox::logInFile('php-errors', 'PatchPanel CSV rollback failed: ' . $e->getMessage() . "\n");
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/csvimport.php');
}

Html::header(__('PatchPanel CSV import', 'patchpanel'), $_SERVER['PHP_SELF'], 'assets');
echo "<div class='container-fluid patchpanel-csv-import'>";
echo "<div class='d-flex align-items-center gap-2 mb-3'><div><h1 class='h2 mb-1'>" .
    htmlescape(__('CSV preview and import', 'patchpanel')) . '</h1>';
echo "<p class='text-muted mb-0'>" .
    htmlescape(__('Preview every row before a transaction-safe import. Applied batches can be rolled back while unchanged.', 'patchpanel')) .
    '</p></div><a class="btn btn-outline-secondary ms-auto" href="' .
    htmlescape($CFG_GLPI['root_doc'] . '/plugins/patchpanel/front/panel.php') . '">' .
    htmlescape(__('Patch panels', 'patchpanel')) . '</a></div>';

echo "<section class='card mb-3'><div class='card-body'>";
echo "<form method='post' enctype='multipart/form-data'>";
echo "<label class='form-label'>" . htmlescape(__('CSV file')) . '</label>';
echo "<input class='form-control' type='file' name='csv_file' accept='.csv,text/csv' required>";
echo "<div class='form-text'>" . htmlescape(
    'panel,port,label,operational_state,media,rear_socket_id,front_networkport_id,rear_cable_color,front_cable_color,cable_id'
) . '</div>';
echo "<button class='btn btn-primary mt-3' type='submit' name='preview_csv' value='1'>" .
    "<i class='ti ti-file-search'></i> " . htmlescape(__('Preview CSV', 'patchpanel')) . '</button>';
Html::closeForm();
echo '</div></section>';

if ($analysis !== null) {
    echo "<section class='card mb-3'><div class='card-header'><h2 class='card-title'>" .
        htmlescape(__('Import preview', 'patchpanel')) . '</h2></div><div class="table-responsive">';
    if ($analysis['errors']) {
        echo "<div class='alert alert-danger m-3'><ul class='mb-0'>";
        foreach ($analysis['errors'] as $error) {
            echo '<li>' . htmlescape($error) . '</li>';
        }
        echo '</ul></div>';
    }
    echo "<table class='table card-table'><thead><tr><th>" . htmlescape(__('Line')) .
        '</th><th>' . htmlescape(__('Status')) . '</th><th>' .
        htmlescape(__('Patch panel', 'patchpanel')) . '</th><th>' .
        htmlescape(__('Port')) . '</th><th>' . htmlescape(__('Changes')) .
        '</th></tr></thead><tbody>';
    foreach ($analysis['rows'] as $row) {
        $changes = [];
        foreach (['label', 'operational_state', 'media'] as $field) {
            if ($row['before']['port'][$field] !== $row['desired']['port'][$field]) {
                $changes[] = $field . ': ' . $row['desired']['port'][$field];
            }
        }
        foreach ($row['desired_endpoints'] as $side => $endpoint) {
            $before = $row['before']['endpoints'][$side];
            if (
                $before['items_id'] !== $endpoint['items_id']
                || $before['cable_color'] !== $endpoint['cable_color']
                || $before['cable_label'] !== $endpoint['cable_label']
            ) {
                $changes[] = $side . ': #' . (int) $endpoint['items_id'];
            }
        }
        echo '<tr><td>' . (int) $row['line'] . '</td><td>';
        if ($row['errors']) {
            echo "<span class='badge bg-danger'>" . htmlescape(__('Invalid')) . '</span><ul class="mb-0">';
            foreach ($row['errors'] as $error) {
                echo '<li>' . htmlescape($error) . '</li>';
            }
            echo '</ul>';
        } else {
            echo "<span class='badge bg-success'>" . htmlescape(__('Ready', 'patchpanel')) . '</span>';
        }
        echo '</td><td>' . htmlescape($row['panel_name']) . '</td><td>#' .
            (int) $row['port_number'] . '</td><td>' .
            htmlescape($changes ? implode('; ', $changes) : __('No changes', 'patchpanel')) .
            '</td></tr>';
    }
    echo '</tbody></table></div>';
    if (!$analysis['errors'] && $analysis['rows'] && $analysis['valid_count'] === count($analysis['rows'])) {
        echo "<div class='card-footer'><form method='post'>";
        echo Html::hidden('csv_payload', ['value' => base64_encode($csv)]);
        echo "<label class='form-check mb-3'><input class='form-check-input' type='checkbox' name='confirm_import' required>";
        echo "<span class='form-check-label'>" .
            htmlescape(__('I reviewed every row and want to apply this import transaction.', 'patchpanel')) .
            '</span></label>';
        echo "<button class='btn btn-success' type='submit' name='apply_csv' value='1'>" .
            "<i class='ti ti-database-import'></i> " . htmlescape(__('Apply CSV import', 'patchpanel')) .
            '</button>';
        Html::closeForm();
        echo '</div>';
    }
    echo '</section>';
}

$batches = PluginPatchpanelCsvImport::getActiveBatches();
echo "<section class='card'><div class='card-header'><h2 class='card-title'>" .
    htmlescape(__('Rollback batches', 'patchpanel')) . '</h2></div><div class="card-body">';
if (!$batches) {
    echo "<p class='text-muted mb-0'>" . htmlescape(__('No active CSV import batch exists.', 'patchpanel')) . '</p>';
}
foreach ($batches as $batch) {
    echo "<form class='d-flex align-items-center gap-3 mb-2' method='post'>";
    echo Html::hidden('batch_uuid', ['value' => $batch['batch_uuid']]);
    echo '<code>' . htmlescape($batch['batch_uuid']) . '</code><span>' .
        sprintf(htmlescape(_n('%d row', '%d rows', (int) $batch['row_count'], 'patchpanel')), (int) $batch['row_count']) .
        '</span><button class="btn btn-sm btn-outline-danger ms-auto" type="submit" name="rollback_batch" value="1">' .
        htmlescape(__('Rollback import', 'patchpanel')) . '</button>';
    Html::closeForm();
}
echo '</div></section></div>';
Html::footer();
