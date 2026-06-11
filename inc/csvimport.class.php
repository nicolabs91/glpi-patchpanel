<?php

final class PluginPatchpanelCsvImport
{
    public const MAX_BYTES = 1048576;
    public const MAX_ROWS = 1000;
    public const HEADERS = [
        'panel',
        'port',
        'label',
        'operational_state',
        'media',
        'rear_socket_id',
        'front_networkport_id',
        'rear_cable_color',
        'front_cable_color',
        'cable_id',
        'cable_label',
    ];

    public static function analyze(string $csv): array
    {
        $parsed = self::parse($csv);
        $analysis = [
            'headers' => $parsed['headers'],
            'rows' => [],
            'errors' => $parsed['errors'],
            'valid_count' => 0,
        ];
        if ($parsed['errors']) {
            return $analysis;
        }

        $seenPorts = [];
        $seenEndpoints = [];
        foreach ($parsed['rows'] as $index => $input) {
            $row = self::analyzeRow($input, $index + 2);
            if ($row['port_id'] > 0) {
                if (isset($seenPorts[$row['port_id']])) {
                    $row['errors'][] = sprintf(
                        __('The same panel port is also used on CSV line %d.', 'patchpanel'),
                        $seenPorts[$row['port_id']]
                    );
                } else {
                    $seenPorts[$row['port_id']] = $row['line'];
                }
            }
            foreach ($row['desired_endpoints'] as $side => $endpoint) {
                if ($endpoint['items_id'] <= 0) {
                    continue;
                }
                $key = $endpoint['itemtype'] . ':' . $endpoint['items_id'];
                if (isset($seenEndpoints[$key])) {
                    $row['errors'][] = sprintf(
                        __('The %1$s endpoint is also used on CSV line %2$d.', 'patchpanel'),
                        $side,
                        $seenEndpoints[$key]
                    );
                } else {
                    $seenEndpoints[$key] = $row['line'];
                }
            }
            $row['status'] = $row['errors'] ? 'invalid' : 'ready';
            if (!$row['errors']) {
                $analysis['valid_count']++;
            }
            $analysis['rows'][] = $row;
        }
        return $analysis;
    }

    private static function parse(string $csv): array
    {
        $errors = [];
        if ($csv === '') {
            return ['headers' => [], 'rows' => [], 'errors' => [__('The CSV file is empty.', 'patchpanel')]];
        }
        if (strlen($csv) > self::MAX_BYTES) {
            return ['headers' => [], 'rows' => [], 'errors' => [__('The CSV file exceeds 1 MB.', 'patchpanel')]];
        }

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '\\');
        if (!is_array($headers)) {
            fclose($stream);
            return ['headers' => [], 'rows' => [], 'errors' => [__('The CSV header is invalid.', 'patchpanel')]];
        }
        $headers = array_map(static fn($value) => trim(mb_strtolower((string) $value)), $headers);
        $missing = array_values(array_diff(self::HEADERS, $headers));
        $unknown = array_values(array_diff($headers, self::HEADERS));
        if ($missing) {
            $errors[] = sprintf(__('Missing CSV columns: %s', 'patchpanel'), implode(', ', $missing));
        }
        if ($unknown) {
            $errors[] = sprintf(__('Unknown CSV columns: %s', 'patchpanel'), implode(', ', $unknown));
        }

        $rows = [];
        while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = sprintf(__('A CSV import is limited to %d rows.', 'patchpanel'), self::MAX_ROWS);
                break;
            }
            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }
            if (count($values) !== count($headers)) {
                $errors[] = sprintf(
                    __('CSV line %d has %d values; %d were expected.', 'patchpanel'),
                    count($rows) + 2,
                    count($values),
                    count($headers)
                );
                continue;
            }
            $rows[] = array_combine($headers, array_map('trim', $values));
        }
        fclose($stream);

        return ['headers' => $headers, 'rows' => $rows, 'errors' => $errors];
    }

    private static function analyzeRow(array $input, int $line): array
    {
        global $DB;

        $errors = [];
        $panelName = trim((string) ($input['panel'] ?? ''));
        $portNumber = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT);
        $panels = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM' => PluginPatchpanelPanel::getTable(),
            'WHERE' => [
                'name' => $panelName,
                'is_deleted' => 0,
                getEntitiesRestrictCriteria(
                    PluginPatchpanelPanel::getTable(),
                    'entities_id',
                    '',
                    true
                ),
            ],
        ]) as $panel) {
            $panels[] = $panel;
        }
        if ($panelName === '' || count($panels) !== 1) {
            $errors[] = count($panels) > 1
                ? __('The panel name is ambiguous in the active entities.', 'patchpanel')
                : __('The panel was not found in the active entities.', 'patchpanel');
        }
        if ($portNumber === false || $portNumber < 1) {
            $errors[] = __('The port number is invalid.', 'patchpanel');
        }

        $port = null;
        if (count($panels) === 1 && $portNumber !== false && $portNumber > 0) {
            $port = $DB->request([
                'FROM' => PluginPatchpanelPanelPort::getTable(),
                'WHERE' => [
                    'plugin_patchpanel_panels_id' => (int) $panels[0]['id'],
                    'number' => (int) $portNumber,
                ],
                'LIMIT' => 1,
            ])->current();
            if (!$port) {
                $errors[] = __('The panel port does not exist.', 'patchpanel');
            }
        }

        $current = $port ? self::snapshot((int) $port['id']) : self::emptySnapshot();
        $desired = self::mergeDesired($current, $input, $errors);
        if ($port) {
            self::validateEndpoint(
                $desired['endpoints'][PluginPatchpanelPortEndpoint::REAR],
                (int) $port['id'],
                $errors
            );
            self::validateEndpoint(
                $desired['endpoints'][PluginPatchpanelPortEndpoint::FRONT],
                (int) $port['id'],
                $errors
            );
        }

        return [
            'line' => $line,
            'input' => $input,
            'panel_name' => $panelName,
            'port_number' => (int) $portNumber,
            'port_id' => (int) ($port['id'] ?? 0),
            'before' => $current,
            'desired' => $desired,
            'desired_endpoints' => $desired['endpoints'],
            'errors' => $errors,
        ];
    }

    private static function mergeDesired(array $current, array $input, array &$errors): array
    {
        $desired = $current;
        foreach (['label', 'operational_state', 'media'] as $field) {
            if (($input[$field] ?? '') !== '') {
                $desired['port'][$field] = (string) $input[$field];
            }
        }
        if (!array_key_exists($desired['port']['operational_state'], PluginPatchpanelPanelPort::getOperationalStateOptions())) {
            $errors[] = __('The operational state is invalid.', 'patchpanel');
        }
        if (!array_key_exists($desired['port']['media'], PluginPatchpanelPanelPort::getMediaOptions())) {
            $errors[] = __('The media type is invalid.', 'patchpanel');
        }

        $mapping = [
            PluginPatchpanelPortEndpoint::REAR => [
                'id_field' => 'rear_socket_id',
                'itemtype' => 'Glpi\\Socket',
                'color_field' => 'rear_cable_color',
            ],
            PluginPatchpanelPortEndpoint::FRONT => [
                'id_field' => 'front_networkport_id',
                'itemtype' => NetworkPort::class,
                'color_field' => 'front_cable_color',
            ],
        ];
        foreach ($mapping as $side => $definition) {
            $endpoint = $desired['endpoints'][$side];
            if (($input[$definition['id_field']] ?? '') !== '') {
                $endpoint['items_id'] = max(0, (int) $input[$definition['id_field']]);
                $endpoint['itemtype'] = $definition['itemtype'];
            }
            if (($input[$definition['color_field']] ?? '') !== '') {
                $color = mb_strtolower((string) $input[$definition['color_field']]);
                if ($color !== 'none' && !preg_match('/^#[0-9a-f]{6}$/', $color)) {
                    $errors[] = sprintf(__('The %s cable color is invalid.', 'patchpanel'), $side);
                } else {
                    $endpoint['cable_color'] = $color === 'none' ? null : $color;
                }
            }
            if ($side === PluginPatchpanelPortEndpoint::FRONT) {
                if (($input['cable_id'] ?? '') !== '') {
                    $endpoint['cables_id'] = max(0, (int) $input['cable_id']);
                    if ($endpoint['cables_id'] > 0) {
                        $cable = new Cable();
                        if (!$cable->getFromDB($endpoint['cables_id']) || !$cable->canViewItem()) {
                            $errors[] = __('The GLPI cable does not exist or is inaccessible.', 'patchpanel');
                        }
                    }
                }
                if (($input['cable_label'] ?? '') !== '') {
                    $endpoint['cable_label'] = $input['cable_label'] === 'none'
                        ? ''
                        : (string) $input['cable_label'];
                }
            }
            $desired['endpoints'][$side] = $endpoint;
        }
        return $desired;
    }

    private static function validateEndpoint(array $endpoint, int $portId, array &$errors): void
    {
        global $DB;

        if ($endpoint['items_id'] <= 0) {
            return;
        }
        $itemtype = $endpoint['itemtype'];
        if (!in_array($itemtype, ['Glpi\\Socket', NetworkPort::class], true)) {
            $errors[] = __('The endpoint type is invalid.', 'patchpanel');
            return;
        }
        $item = new $itemtype();
        if (!$item->getFromDB((int) $endpoint['items_id']) || !$item->canViewItem()) {
            $errors[] = sprintf(__('The %s endpoint does not exist or is inaccessible.', 'patchpanel'), $endpoint['side']);
            return;
        }
        $used = $DB->request([
            'SELECT' => ['plugin_patchpanel_panelports_id'],
            'FROM' => PluginPatchpanelPortEndpoint::getTable(),
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => (int) $endpoint['items_id'],
                'NOT' => ['plugin_patchpanel_panelports_id' => $portId],
            ],
            'LIMIT' => 1,
        ])->current();
        if ($used) {
            $errors[] = sprintf(__('The %s endpoint is already assigned to another panel port.', 'patchpanel'), $endpoint['side']);
        }
    }

    private static function emptySnapshot(): array
    {
        return [
            'port' => ['label' => '', 'operational_state' => 'active', 'media' => 'copper'],
            'endpoints' => [
                PluginPatchpanelPortEndpoint::REAR => self::emptyEndpoint(PluginPatchpanelPortEndpoint::REAR),
                PluginPatchpanelPortEndpoint::FRONT => self::emptyEndpoint(PluginPatchpanelPortEndpoint::FRONT),
            ],
        ];
    }

    private static function emptyEndpoint(string $side): array
    {
        return [
            'id' => 0,
            'side' => $side,
            'itemtype' => $side === PluginPatchpanelPortEndpoint::REAR ? 'Glpi\\Socket' : NetworkPort::class,
            'items_id' => 0,
            'cables_id' => 0,
            'cable_color' => null,
            'cable_label' => '',
            'comment' => null,
        ];
    }

    public static function snapshot(int $portId): array
    {
        $port = new PluginPatchpanelPanelPort();
        if (!$port->getFromDB($portId)) {
            throw new RuntimeException(__('The panel port no longer exists.', 'patchpanel'));
        }
        $snapshot = self::emptySnapshot();
        $snapshot['port'] = [
            'label' => (string) ($port->fields['label'] ?? ''),
            'operational_state' => (string) ($port->fields['operational_state'] ?? 'active'),
            'media' => (string) ($port->fields['media'] ?? 'copper'),
        ];
        foreach (PluginPatchpanelPortEndpoint::getForPort($portId) as $side => $endpoint) {
            $snapshot['endpoints'][$side] = [
                'id' => (int) $endpoint['id'],
                'side' => $side,
                'itemtype' => (string) $endpoint['itemtype'],
                'items_id' => (int) $endpoint['items_id'],
                'cables_id' => (int) $endpoint['cables_id'],
                'cable_color' => $endpoint['cable_color'],
                'cable_label' => (string) ($endpoint['cable_label'] ?? ''),
                'comment' => $endpoint['comment'],
            ];
        }
        return $snapshot;
    }

    public static function apply(string $csv): array
    {
        global $DB;

        $analysis = self::analyze($csv);
        $rowErrors = array_filter($analysis['rows'], static fn($row) => $row['errors']);
        if ($analysis['errors'] || $rowErrors || !$analysis['rows']) {
            throw new InvalidArgumentException(__('The CSV preview contains errors.', 'patchpanel'));
        }

        $batch = bin2hex(random_bytes(16));
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->beginTransaction();
        try {
            $DB->insert('glpi_plugin_patchpanel_importbatches', [
                'batch_uuid' => $batch,
                'status' => 'applied',
                'row_count' => count($analysis['rows']),
                'users_id' => (int) Session::getLoginUserID(),
                'date_creation' => $now,
                'date_mod' => $now,
            ]);
            foreach ($analysis['rows'] as $row) {
                $portId = (int) $row['port_id'];
                $port = new PluginPatchpanelPanelPort();
                $port->check($portId, UPDATE);
                $before = self::snapshot($portId);
                $DB->update(
                    PluginPatchpanelPanelPort::getTable(),
                    $row['desired']['port'] + ['date_mod' => $now],
                    ['id' => $portId]
                );
                if (!PluginPatchpanelPortEndpoint::saveForPort(
                    $portId,
                    self::endpointInput($row['desired']),
                    false
                )) {
                    throw new RuntimeException(__('An endpoint could not be saved.', 'patchpanel'));
                }
                $after = self::snapshot($portId);
                $DB->insert('glpi_plugin_patchpanel_importchanges', [
                    'batch_uuid' => $batch,
                    'plugin_patchpanel_panelports_id' => $portId,
                    'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after_json' => json_encode($after, JSON_THROW_ON_ERROR),
                    'date_creation' => $now,
                ]);
                $port = new PluginPatchpanelPanelPort();
                $port->getFromDB($portId);
                PluginPatchpanelAudit::record(
                    (int) $port->fields['plugin_patchpanel_panels_id'],
                    $portId,
                    'update',
                    'csv_import',
                    sprintf(__('Imported CSV line %d', 'patchpanel'), $row['line']),
                    $before,
                    $after
                );
            }
            $DB->commit();
            return ['batch_uuid' => $batch, 'row_count' => count($analysis['rows'])];
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    private static function endpointInput(array $snapshot): array
    {
        $rear = $snapshot['endpoints'][PluginPatchpanelPortEndpoint::REAR];
        $front = $snapshot['endpoints'][PluginPatchpanelPortEndpoint::FRONT];
        return [
            'rear_items_id' => $rear['items_id'],
            'rear_cable_color' => $rear['cable_color'] ?? '',
            'front_items_id' => $front['items_id'],
            'front_cable_color' => $front['cable_color'] ?? '',
            'front_cables_id' => $front['cables_id'],
            'front_cable_label' => $front['cable_label'],
        ];
    }

    public static function rollback(string $batch): int
    {
        global $DB;

        $batchRow = $DB->request([
            'FROM' => 'glpi_plugin_patchpanel_importbatches',
            'WHERE' => ['batch_uuid' => $batch, 'status' => 'applied'],
            'LIMIT' => 1,
        ])->current();
        if (!$batchRow) {
            throw new InvalidArgumentException(__('The import batch is unavailable or already rolled back.', 'patchpanel'));
        }
        $changes = iterator_to_array($DB->request([
            'FROM' => 'glpi_plugin_patchpanel_importchanges',
            'WHERE' => ['batch_uuid' => $batch],
            'ORDER' => ['id DESC'],
        ]));

        foreach ($changes as $change) {
            $expected = json_decode($change['after_json'], true, 512, JSON_THROW_ON_ERROR);
            if (self::snapshot((int) $change['plugin_patchpanel_panelports_id']) !== $expected) {
                throw new DomainException(
                    __('Rollback stopped because an imported port was changed after the import.', 'patchpanel')
                );
            }
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->beginTransaction();
        try {
            foreach ($changes as $change) {
                $portId = (int) $change['plugin_patchpanel_panelports_id'];
                $port = new PluginPatchpanelPanelPort();
                $port->check($portId, UPDATE);
                $before = json_decode($change['before_json'], true, 512, JSON_THROW_ON_ERROR);
                $after = json_decode($change['after_json'], true, 512, JSON_THROW_ON_ERROR);
                $DB->update(
                    PluginPatchpanelPanelPort::getTable(),
                    $before['port'] + ['date_mod' => $now],
                    ['id' => $portId]
                );
                $DB->delete(
                    PluginPatchpanelPortEndpoint::getTable(),
                    ['plugin_patchpanel_panelports_id' => $portId]
                );
                foreach ($before['endpoints'] as $endpoint) {
                    if ((int) $endpoint['items_id'] <= 0) {
                        continue;
                    }
                    $DB->insert(
                        PluginPatchpanelPortEndpoint::getTable(),
                        $endpoint + [
                            'plugin_patchpanel_panelports_id' => $portId,
                            'date_creation' => $now,
                            'date_mod' => $now,
                        ]
                    );
                }
                $port = new PluginPatchpanelPanelPort();
                $port->getFromDB($portId);
                PluginPatchpanelAudit::record(
                    (int) $port->fields['plugin_patchpanel_panels_id'],
                    $portId,
                    'rollback',
                    'csv_import',
                    __('Rolled back CSV import change', 'patchpanel'),
                    $after,
                    $before
                );
            }
            $DB->update('glpi_plugin_patchpanel_importbatches', [
                'status' => 'rolled_back',
                'date_mod' => $now,
            ], ['batch_uuid' => $batch]);
            $DB->commit();
            return count($changes);
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    public static function getActiveBatches(): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM' => 'glpi_plugin_patchpanel_importbatches',
            'WHERE' => ['status' => 'applied'],
            'ORDER' => ['date_creation DESC', 'id DESC'],
        ]));
    }
}
