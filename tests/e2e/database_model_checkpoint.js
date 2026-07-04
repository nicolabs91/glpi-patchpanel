const { execFileSync } = require('child_process');

function queryRows(sql) {
  const output = execFileSync('docker', [
    'exec',
    'glpi-db',
    'mariadb',
    '-uglpi',
    '-pQ7f2mK9xT8pL4vN6dR1sW3yZ',
    'glpi',
    '--batch',
    '--raw',
    '-e',
    sql,
  ], { encoding: 'utf8' }).trim();

  if (!output) {
    return [];
  }
  const [header, ...lines] = output.split('\n');
  const columns = header.split('\t');
  return lines.map(line => {
    const values = line.split('\t');
    return Object.fromEntries(columns.map((column, index) => [column, values[index] ?? '']));
  });
}

function scalar(sql, column) {
  const rows = queryRows(sql);
  return Number(rows[0]?.[column] ?? 0);
}

function hasIndex(table, indexName, columns) {
  const rows = queryRows(`SHOW INDEX FROM ${table}`);
  const actual = rows
    .filter(row => row.Key_name === indexName)
    .sort((a, b) => Number(a.Seq_in_index) - Number(b.Seq_in_index))
    .map(row => row.Column_name);
  return JSON.stringify(actual) === JSON.stringify(columns);
}

const expectedIndexes = [
  ['glpi_plugin_patchpanel_portendpoints', 'port_side', ['plugin_patchpanel_panelports_id', 'side']],
  ['glpi_plugin_patchpanel_portendpoints', 'endpoint', ['itemtype', 'items_id']],
  ['glpi_plugin_patchpanel_panelports', 'panel_number', ['plugin_patchpanel_panels_id', 'number']],
  ['glpi_plugin_patchpanel_panelports', 'panel_layout', ['plugin_patchpanel_panels_id', 'row', 'position']],
  ['glpi_networkports', 'item', ['itemtype', 'items_id']],
  ['glpi_sockets', 'item', ['itemtype', 'items_id']],
  ['glpi_sockets', 'networkports_id', ['networkports_id']],
];

const indexResults = Object.fromEntries(
  expectedIndexes.map(([table, indexName, columns]) => [
    `${table}.${indexName}`,
    hasIndex(table, indexName, columns),
  ])
);

const orphanEndpoints = scalar(`
  SELECT COUNT(*) AS count
  FROM glpi_plugin_patchpanel_portendpoints e
  LEFT JOIN glpi_plugin_patchpanel_panelports p
    ON p.id = e.plugin_patchpanel_panelports_id
  WHERE p.id IS NULL
`, 'count');

const orphanPanels = scalar(`
  SELECT COUNT(*) AS count
  FROM glpi_plugin_patchpanel_panelports p
  LEFT JOIN glpi_plugin_patchpanel_panels pa
    ON pa.id = p.plugin_patchpanel_panels_id
  WHERE pa.id IS NULL
`, 'count');

const duplicatePortSides = scalar(`
  SELECT COUNT(*) AS count
  FROM (
    SELECT plugin_patchpanel_panelports_id, side, COUNT(*) AS duplicate_count
    FROM glpi_plugin_patchpanel_portendpoints
    GROUP BY plugin_patchpanel_panelports_id, side
    HAVING duplicate_count > 1
  ) duplicates
`, 'count');

const duplicateEndpoints = scalar(`
  SELECT COUNT(*) AS count
  FROM (
    SELECT itemtype, items_id, COUNT(*) AS duplicate_count
    FROM glpi_plugin_patchpanel_portendpoints
    GROUP BY itemtype, items_id
    HAVING duplicate_count > 1
  ) duplicates
`, 'count');

const invalidEndpointTypes = scalar(`
  SELECT COUNT(*) AS count
  FROM glpi_plugin_patchpanel_portendpoints
  WHERE (side = 'rear' AND itemtype <> 'Glpi\\\\Socket')
     OR (side = 'front' AND itemtype <> 'NetworkPort')
     OR side NOT IN ('rear', 'front')
`, 'count');

const brokenSocketReferences = scalar(`
  SELECT COUNT(*) AS count
  FROM glpi_plugin_patchpanel_portendpoints e
  LEFT JOIN glpi_sockets s ON s.id = e.items_id
  WHERE e.itemtype = 'Glpi\\\\Socket'
    AND s.id IS NULL
`, 'count');

const brokenNetworkPortReferences = scalar(`
  SELECT COUNT(*) AS count
  FROM glpi_plugin_patchpanel_portendpoints e
  LEFT JOIN glpi_networkports np ON np.id = e.items_id
  WHERE e.itemtype = 'NetworkPort'
    AND (np.id IS NULL OR np.is_deleted <> 0)
`, 'count');

const invalidPanelPortStateOrMedia = scalar(`
  SELECT COUNT(*) AS count
  FROM glpi_plugin_patchpanel_panelports
  WHERE operational_state NOT IN ('active', 'reserved')
     OR media NOT IN ('copper', 'fiber-sm', 'fiber-mm', 'other')
`, 'count');

const invalidLayoutNumbers = scalar(`
  SELECT COUNT(*) AS count
  FROM glpi_plugin_patchpanel_panels pa
  LEFT JOIN glpi_plugin_patchpanel_panelports p
    ON p.plugin_patchpanel_panels_id = pa.id
  WHERE pa.port_count < 1
     OR pa.rows < 1
     OR p.number < 1
     OR p.row < 1
     OR p.position < 1
`, 'count');

const activeImportBatchesWithoutChanges = scalar(`
  SELECT COUNT(*) AS count
  FROM glpi_plugin_patchpanel_importbatches b
  LEFT JOIN glpi_plugin_patchpanel_importchanges c
    ON c.batch_uuid = b.batch_uuid
  WHERE b.status = 'applied'
  GROUP BY b.id
  HAVING COUNT(c.id) = 0
`, 'count');

const result = {
  indexes: indexResults,
  integrity: {
    orphanEndpoints,
    orphanPanels,
    duplicatePortSides,
    duplicateEndpoints,
    invalidEndpointTypes,
    brokenSocketReferences,
    brokenNetworkPortReferences,
    invalidPanelPortStateOrMedia,
    invalidLayoutNumbers,
    activeImportBatchesWithoutChanges,
  },
};

console.log(JSON.stringify(result, null, 2));

if (
  Object.values(indexResults).some(value => !value)
  || orphanEndpoints !== 0
  || orphanPanels !== 0
  || duplicatePortSides !== 0
  || duplicateEndpoints !== 0
  || invalidEndpointTypes !== 0
  || brokenSocketReferences !== 0
  || brokenNetworkPortReferences !== 0
  || invalidPanelPortStateOrMedia !== 0
  || invalidLayoutNumbers !== 0
  || activeImportBatchesWithoutChanges !== 0
) {
  process.exitCode = 1;
}
