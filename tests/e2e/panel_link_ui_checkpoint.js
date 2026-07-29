const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';
const panelPortId = Number(process.env.GLPI_PANEL_PORT_ID || 31);

(async () => {
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
    || metadataFields !== 5
    || selectedPeerIsSelf !== 0
    || errors.length
  ) {
    process.exitCode = 1;
  }
})();
