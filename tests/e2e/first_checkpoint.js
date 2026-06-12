const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

async function selectValue(page, name, value, label) {
  const selector = `select[name="${name}"]`;
  await page.locator(selector).evaluate((element, option) => {
    const value = String(option.value);
    if (![...element.options].some(item => item.value === value)) {
      element.add(new Option(option.label, value));
    }
    element.value = value;
    element.dispatchEvent(new Event('change', { bubbles: true }));
  }, { value, label });
}

(async () => {
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1600, height: 1100 } });
  const browserErrors = [];

  page.on('pageerror', error => browserErrors.push(`pageerror: ${error.message}`));
  page.on('console', message => {
    if (message.type() === 'error') {
      browserErrors.push(`console: ${message.text()}`);
    }
  });
  page.on('response', response => {
    if (response.status() >= 400) {
      browserErrors.push(`${response.status()} ${response.url()}`);
    }
  });

  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.fill('input[name="login_name"]', username);
  await page.fill('input[name="login_password"]', password);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');

  const menuLink = page.locator('a[href="/plugins/patchpanel/front/panel.php"]', {
    hasText: 'Patch panels',
  }).first();
  if (await menuLink.count() !== 1) {
    throw new Error('Patch panels menu link is missing from the GLPI start page');
  }
  await page.locator(
    'li.nav-item.dropdown[aria-label="Assets"] [data-testid="sidebar-menu-toggle"]'
  ).click();
  await menuLink.waitFor({ state: 'visible' });
  const [listResponse] = await Promise.all([
    page.waitForResponse(response =>
      response.request().resourceType() === 'document'
      && response.url() === `${baseUrl}/plugins/patchpanel/front/panel.php`
    ),
    menuLink.click(),
  ]);
  await page.waitForURL(`${baseUrl}/plugins/patchpanel/front/panel.php`);
  await page.waitForLoadState('networkidle');
  const listBody = await page.locator('body').innerText();
  const addPanelButton = page.locator(
    'a[href="/plugins/patchpanel/front/panel.form.php?id=-1"]',
    { hasText: 'Add patch panel' }
  ).first();
  const addPanelVisible = await addPanelButton.isVisible();

  const legacyResponse = await page.goto(
    `${baseUrl}/plugins/patchpanel/front/patchpanel.php`,
    { waitUntil: 'networkidle' }
  );
  const legacyEntryRedirected =
    page.url() === `${baseUrl}/plugins/patchpanel/front/panel.php`;

  await addPanelButton.click();
  await page.waitForURL(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`);
  await page.waitForLoadState('networkidle');
  const panelName = `PP-V2-E2E-${Date.now()}`;
  await page.fill('input[name="name"]', panelName);
  await page.fill('input[name="port_count"]', '24');
  await selectValue(page, 'rows', 1, '1');
  await selectValue(page, 'media', 'copper', 'Copper');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');

  const panelUrl = page.url();
  const panelId = Number(new URL(panelUrl).searchParams.get('id'));
  if (!panelId) {
    throw new Error(`Panel creation did not redirect to a valid ID: ${panelUrl}`);
  }

  const visualTab = page.locator('a, button').filter({ hasText: /Visual panel/i }).first();
  if (await visualTab.count()) {
    await visualTab.click();
    await page.locator('.patchpanel-port').first().waitFor({ state: 'visible' });
  }

  const visualBody = await page.locator('body').innerText();
  const visualColumns = await page.locator('.patchpanel-grid').evaluate(element =>
    getComputedStyle(element).getPropertyValue('--patchpanel-columns').trim()
  );
  const portTiles = page.locator('.patchpanel-port');
  const portCount = await portTiles.count();
  await page.screenshot({
    path: 'artifacts/patchpanel-v2-first-visual.png',
    fullPage: true,
  });

  await portTiles.first().click();
  await page.waitForLoadState('networkidle');
  const portUrl = page.url();
  const portId = Number(new URL(portUrl).searchParams.get('id'));
  if (!portId) {
    throw new Error(`Port tile did not lead to a valid port: ${portUrl}`);
  }

  await selectValue(page, 'rear_items_id', 299, 'Kamer 0201 Wall outlet');
  await selectValue(page, 'front_items_id', 227, 'SW-L1-IDF-B - Port 02');
  await selectValue(page, 'rear_cable_color', '#0d6efd', 'Blue');
  await selectValue(page, 'front_cable_color', '#ffc107', 'Yellow');
  await page.fill('input[name="front_cable_label"]', 'CP-V2-E2E');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');
  await page.locator('.patchpanel-route').waitFor({ state: 'visible' });

  const routeBody = await page.locator('body').innerText();
  const routeLinks = await page.locator('.patchpanel-route-step[href]').count();
  const routeZones = await page.locator('.patchpanel-route-step').evaluateAll(steps =>
    steps.map(step => step.getAttribute('data-route-zone'))
  );
  const routeLegendItems = await page.locator('.patchpanel-route-legend-item').count();
  await page.screenshot({
    path: 'artifacts/patchpanel-v2-first-route.png',
    fullPage: true,
  });

  const result = {
    list_status: listResponse.status(),
    list_loaded: listBody.includes('Patch panels'),
    add_panel_visible: addPanelVisible,
    legacy_entry_status: legacyResponse.status(),
    legacy_entry_redirected: legacyEntryRedirected,
    legacy_notice: visualBody.includes('4 panels') && visualBody.includes('72 ports'),
    panel_id: panelId,
    port_id: portId,
    visual_port_count: portCount,
    visual_columns: Number(visualColumns),
    route: {
      terminal: routeBody.includes('TV 026'),
      socket: routeBody.includes('Kamer 0201 Wall outlet'),
      panel: routeBody.includes(panelName),
      access_switch: routeBody.includes('SW-L1-IDF-B'),
      core_switch: routeBody.includes('SW-L1-MDF-CORE-01'),
      firewall_router: routeBody.includes('FW-L1-MDF-01'),
      clickable_steps: routeLinks,
      zones: routeZones,
      legend_items: routeLegendItems,
    },
    unexpected_error: routeBody.includes('An unexpected error occurred'),
    browser_errors: browserErrors,
  };

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  const csrfToken = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  const cleanupResponse = await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panel.form.php`,
    {
      form: {
        id: String(panelId),
        purge: '1',
        _glpi_csrf_token: csrfToken,
      },
      maxRedirects: 0,
    }
  );
  result.cleanup_status = cleanupResponse.status();
  console.log(JSON.stringify(result, null, 2));

  await browser.close();

  const routeComplete = Object.entries(result.route)
    .filter(([key]) => !['clickable_steps', 'zones', 'legend_items'].includes(key))
    .every(([, value]) => value === true);
  const expectedZones = [
    'endpoint', 'endpoint', 'outlet', 'panel', 'panel', 'access', 'access',
    'access', 'core', 'core', 'core', 'gateway', 'gateway',
  ];
  if (
    result.list_status !== 200
    || !result.add_panel_visible
    || result.legacy_entry_status !== 200
    || !result.legacy_entry_redirected
    || result.visual_port_count !== 24
    || result.visual_columns !== 24
    || !routeComplete
    || result.route.clickable_steps < 7
    || result.route.legend_items !== 6
    || JSON.stringify(result.route.zones) !== JSON.stringify(expectedZones)
    || result.unexpected_error
    || result.browser_errors.length
    || ![200, 302, 303].includes(result.cleanup_status)
  ) {
    process.exitCode = 1;
  }
})();
