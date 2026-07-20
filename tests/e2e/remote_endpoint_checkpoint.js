const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';
const panelPortId = Number(process.env.GLPI_PANEL_PORT_ID || 31);
const otherPanelPortId = Number(process.env.GLPI_OTHER_PANEL_PORT_ID || 32);

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

  const formUrl = `${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${panelPortId}`;
  await page.goto(formUrl, { waitUntil: 'networkidle' });
  const selector = page.locator('select[name="rear_endpoint"]');
  const groups = await selector.locator('optgroup').evaluateAll(nodes =>
    nodes.map(node => ({ label: node.label, count: node.children.length }))
  );
  const deviceOption = selector.locator('optgroup[label="Device ports"] option').first();
  const deviceValue = await deviceOption.getAttribute('value');
  const deviceLabel = (await deviceOption.innerText()).trim();
  if (!deviceValue) throw new Error('No available device network port found');

  await selector.selectOption(deviceValue);
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');
  const persisted = await page.locator('select[name="rear_endpoint"]').inputValue();
  const routeShowsDevice = (await page.locator('body').innerText()).includes(deviceLabel.split(' · ')[0]);

  await page.goto(
    `${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${otherPanelPortId}`,
    { waitUntil: 'networkidle' },
  );
  const reusedVisible = await page.locator(
    `select[name="rear_endpoint"] option[value="${deviceValue}"]`
  ).count();

  await page.goto(formUrl, { waitUntil: 'networkidle' });
  await page.locator('select[name="rear_endpoint"]').selectOption('0');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');

  const result = {
    groups,
    selected_device: { value: deviceValue, label: deviceLabel },
    persisted,
    route_shows_device: routeShowsDevice,
    reused_visible_elsewhere: reusedVisible,
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    !groups.some(group => group.label === 'Wall outlets' && group.count > 0)
    || !groups.some(group => group.label === 'Device ports' && group.count > 0)
    || persisted !== deviceValue
    || !routeShowsDevice
    || reusedVisible !== 0
    || errors.length
  ) {
    process.exitCode = 1;
  }
})();
