const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';
const panelPortId = Number(process.env.GLPI_PANEL_PORT_ID || 31);
const panelId = Number(process.env.GLPI_PANEL_ID || 8);

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
  const socketSelector = page.locator('select[name="rear_items_id"]');
  const socketSelectorCount = await socketSelector.count();
  const mixedSelectorCount = await page.locator('select[name="rear_endpoint"]').count();
  await socketSelector.locator('xpath=following-sibling::span[contains(@class,"select2")]').click();
  await page.locator('.select2-search__field').last().fill('HTL-L1');
  await page.waitForTimeout(750);
  const socketOptions = await page.locator('.select2-results__option').count();
  await page.keyboard.press('Escape');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`, {
    waitUntil: 'networkidle',
  });
  const createInventoryFieldCount = await page.locator('input[name="otherserial"]').count();
  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  const editInventoryFieldCount = await page.locator('input[name="otherserial"]').count();

  const result = {
    socket_selector_visible: socketSelectorCount,
    socket_options: socketOptions,
    mixed_remote_selector_visible: mixedSelectorCount,
    create_inventory_field_visible: createInventoryFieldCount,
    edit_inventory_field_visible: editInventoryFieldCount,
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    result.socket_selector_visible !== 1
    || result.socket_options < 1
    || result.mixed_remote_selector_visible !== 0
    || result.create_inventory_field_visible !== 0
    || result.edit_inventory_field_visible !== 0
    || errors.length
  ) {
    process.exitCode = 1;
  }
})();
