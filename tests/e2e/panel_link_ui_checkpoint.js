const { launchBrowser } = require('./helpers');
const { execFileSync } = require('child_process');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

function queryDb(sql) {
  return execFileSync('docker', [
    'exec',
    'glpi-db',
    'mariadb',
    '-uglpi',
    `-p${process.env.GLPI_DB_PASSWORD || 'glpi'}`,
    'glpi',
    '-N',
    '-e',
    sql,
  ], { encoding: 'utf8' }).trim();
}

function findFreePanelPortId() {
  const explicitId = Number(process.env.GLPI_PANEL_PORT_ID || 0);
  if (explicitId > 0) {
    return explicitId;
  }

  const discoveredId = Number(queryDb(`
    SELECT pp.id
    FROM glpi_plugin_patchpanel_panelports pp
    INNER JOIN glpi_plugin_patchpanel_panels p
      ON p.id = pp.plugin_patchpanel_panels_id
    LEFT JOIN glpi_plugin_patchpanel_portendpoints pe
      ON pe.plugin_patchpanel_panelports_id = pp.id
      AND pe.side = 'rear'
    LEFT JOIN glpi_plugin_patchpanel_panelportlinks pla
      ON pla.panelports_id_a = pp.id
      AND pla.is_active = 1
    LEFT JOIN glpi_plugin_patchpanel_panelportlinks plb
      ON plb.panelports_id_b = pp.id
      AND plb.is_active = 1
    WHERE p.is_deleted = 0
      AND pe.id IS NULL
      AND pla.id IS NULL
      AND plb.id IS NULL
    ORDER BY pp.id
    LIMIT 1
  `));
  if (!discoveredId) {
    throw new Error('No free panel port is available for the panel-link UI checkpoint');
  }
  return discoveredId;
}

(async () => {
  const panelPortId = findFreePanelPortId();
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1600, height: 1100 } });
  const errors = [];
  page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
  page.on('console', message => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });
  page.on('response', response => {
    if (response.status() >= 400) errors.push(`${response.status()} ${response.url()}`);
  });

  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.fill('input[name="login_name"]', username);
  await page.fill('input[name="login_password"]', password);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');
  await page.goto(
    `${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${panelPortId}`,
    { waitUntil: 'networkidle' },
  );

  const rearTypes = await page.locator('select[name="rear_endpoint_type"] option')
    .evaluateAll(options => options.map(option => option.value));
  const peerOptions = await page.locator('select[name="rear_panelport_id"] option').count();
  const metadataFields = await page.locator([
    'input[name="panel_link_cable_label"]',
    'select[name="panel_link_cable_color"]',
    'select[name="panel_link_media_type"]',
    'input[name="panel_link_length"]',
    'textarea[name="panel_link_comment"]',
  ].join(', ')).count();
  const selectedPeerIsSelf = await page.locator(
    `select[name="rear_panelport_id"] option[value="${panelPortId}"]`,
  ).count();

  const result = {
    panel_port_id: panelPortId,
    rear_connection_types: rearTypes,
    selectable_peer_options: peerOptions,
    metadata_fields: metadataFields,
    self_option_count: selectedPeerIsSelf,
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    rearTypes.join(',') !== 'none,socket,panel_port'
    || peerOptions < 2
    || metadataFields !== 0
    || selectedPeerIsSelf !== 0
    || errors.length
  ) {
    process.exitCode = 1;
  }
})();
