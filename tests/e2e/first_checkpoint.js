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
    '-pQ7f2mK9xT8pL4vN6dR1sW3yZ',
    'glpi',
    '-N',
    '-e',
    sql,
  ], { encoding: 'utf8' }).trim();
}

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
  const hiddenDailyTools = {
    health: await page.locator('.patchpanel-list-actions a', { hasText: /Health check/i }).count(),
    csv: await page.locator('.patchpanel-list-actions a', { hasText: /CSV import/i }).count(),
    legacy: await page.locator('.patchpanel-list-actions a', {
      hasText: /Analyze legacy PatchPanel data/i,
    }).count(),
  };
  const configResponse = await page.goto(
    `${baseUrl}/plugins/patchpanel/front/config.php`,
    { waitUntil: 'networkidle' },
  );
  const configTools = {
    health: await page.locator('a[href="/plugins/patchpanel/front/health.php"]', {
      hasText: /Health check/i,
    }).count(),
    csv: await page.locator('a[href="/plugins/patchpanel/front/csvimport.php"]', {
      hasText: /CSV import/i,
    }).count(),
    legacy: await page.locator('a', { hasText: /Analyze legacy PatchPanel data/i }).count(),
  };
  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.php`, {
    waitUntil: 'networkidle',
  });
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
  const overviewCards = await page.locator('.patchpanel-overview-card').evaluateAll(cards =>
    cards.map(card => card.textContent.replace(/\s+/g, ' ').trim())
  );
  const quickActions = {
    routes: await page.locator('a', { hasText: /Search routes/i }).count(),
    health: await page.locator('a', { hasText: /Health check/i }).count(),
    audit: await page.locator('a', { hasText: /Audit history/i }).count(),
    labels: await page.locator('a', { hasText: /Print QR labels/i }).count(),
  };
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
  if (await page.locator('[name="front_cables_id"]').count()) {
    throw new Error('The redundant GLPI cable field is visible in the standard port form');
  }
  const portWorkflow = {
    visible: await page.locator('.patchpanel-port-workflow').isVisible(),
    status: await page.locator('.patchpanel-port-workflow').innerText(),
    visual_link: await page.locator('.patchpanel-port-workflow a', {
      hasText: /Visual panel/i,
    }).count(),
    next_link: await page.locator('.patchpanel-port-workflow a', {
      hasText: /Next port/i,
    }).count(),
  };

  queryDb(
    "UPDATE glpi_sockets SET itemtype = 'NetworkEquipment', items_id = 278, networkports_id = 332 WHERE id = 299"
  );
  await selectValue(page, 'rear_items_id', 299, 'NLH-R0201-WA01 - Room 0201 wall outlet');
  await selectValue(page, 'front_items_id', 227, 'NLH-F01-IDF-B-SW01 - Gi1/0/02');
  await selectValue(page, 'front_cable_color', '#ffc107', 'Yellow');
  await page.fill('input[name="front_cable_label"]', 'CP-V2-E2E');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');
  await page.locator('.patchpanel-route').waitFor({ state: 'visible' });

  const routeBody = await page.locator('body').innerText();
  const routeFullText = await page.locator('.patchpanel-route').evaluate(element =>
    element.textContent.replace(/\s+/g, ' ').trim()
  );
  const routeMoreToggles = await page.locator('.patchpanel-route-more-toggle').count();
  const routeMoreCollapsed = await page.locator('.patchpanel-route-more').evaluate(element => !element.open);
  const routeLinks = await page.locator('.patchpanel-route-step[href]').count();
  const routeZones = await page.locator('.patchpanel-route-step').evaluateAll(steps =>
    steps.map(step => step.getAttribute('data-route-zone'))
  );
  const routeStepColors = await page.locator('.patchpanel-route-step').evaluateAll(steps =>
    Object.fromEntries(steps.map(step => [
      step.getAttribute('data-route-zone'),
      getComputedStyle(step).backgroundColor,
    ]))
  );
  const routeStepBorders = await page.locator('.patchpanel-route-step').evaluateAll(steps =>
    Object.fromEntries(steps.map(step => [
      step.getAttribute('data-route-zone'),
      getComputedStyle(step).borderColor,
    ]))
  );
  const routeLegendItems = await page.locator('.patchpanel-route-legend-item').count();
  const routeLegendColors = await page.locator('.patchpanel-route-legend-item').evaluateAll(items =>
    Object.fromEntries(items.map(item => [
      item.getAttribute('class').match(/patchpanel-route-zone-([a-z]+)/)?.[1],
      getComputedStyle(item).backgroundColor,
    ]))
  );
  const routeLegendBorders = await page.locator('.patchpanel-route-legend-item').evaluateAll(items =>
    Object.fromEntries(items.map(item => [
      item.getAttribute('class').match(/patchpanel-route-zone-([a-z]+)/)?.[1],
      getComputedStyle(item).borderColor,
    ]))
  );
  await page.screenshot({
    path: 'artifacts/patchpanel-v2-first-route.png',
    fullPage: true,
  });

  const result = {
    list_status: listResponse.status(),
    list_loaded: listBody.includes('Patch panels'),
    hidden_daily_tools: hiddenDailyTools,
    config_status: configResponse.status(),
    config_tools: configTools,
    add_panel_visible: addPanelVisible,
    legacy_entry_status: legacyResponse.status(),
    legacy_entry_redirected: legacyEntryRedirected,
    legacy_notice_hidden: !visualBody.includes('Legacy source detected'),
    panel_id: panelId,
    port_id: portId,
    visual_port_count: portCount,
    visual_columns: Number(visualColumns),
    overview_cards: overviewCards,
    quick_actions: quickActions,
    port_workflow: portWorkflow,
    route: {
      terminal: routeBody.includes('NLH-R0201-TV01'),
      socket: routeBody.includes('NLH-R0201-WA01'),
      panel: routeBody.includes(panelName),
      access_switch: routeBody.includes('NLH-F01-IDF-B-SW01'),
      core_collapsed_by_default: routeMoreCollapsed,
      core_switch: routeFullText.includes('NLH-MDF-CORE-SW01'),
      firewall_router: routeFullText.includes('NLH-MDF-FW01'),
      more_toggle: routeMoreToggles === 1,
      clickable_steps: routeLinks,
      zones: routeZones,
      step_colors: routeStepColors,
      step_borders: routeStepBorders,
      legend_items: routeLegendItems,
      legend_colors: routeLegendColors,
      legend_borders: routeLegendBorders,
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
    .filter(([key]) => ![
      'clickable_steps', 'zones', 'step_colors', 'step_borders',
      'legend_items', 'legend_colors', 'legend_borders',
    ].includes(key))
    .every(([, value]) => value === true);
  const expectedZones = [
    'endpoint', 'connection', 'panel', 'panel', 'access', 'access', 'core', 'core', 'gateway',
  ];
  if (
    result.list_status !== 200
    || !result.add_panel_visible
    || result.config_status !== 200
    || result.config_tools.health !== 1
    || result.config_tools.csv !== 1
    || result.config_tools.legacy !== 0
    || result.legacy_entry_status !== 200
    || !result.legacy_entry_redirected
    || Object.values(result.hidden_daily_tools).some(count => count !== 0)
    || result.visual_port_count !== 24
    || result.visual_columns !== 24
    || result.overview_cards.length !== 3
    || !result.overview_cards.some(card => card.includes('Free') && card.includes('24'))
    || result.quick_actions.routes < 1
    || result.quick_actions.health !== 0
    || result.quick_actions.audit < 1
    || result.quick_actions.labels !== 0
    || !result.legacy_notice_hidden
    || !result.port_workflow.visible
    || !result.port_workflow.status.includes('Free')
    || result.port_workflow.visual_link !== 1
    || result.port_workflow.next_link !== 1
    || !routeComplete
    || result.route.clickable_steps < 7
    || result.route.legend_items !== 6
    || result.route.legend_colors.endpoint !== 'rgb(237, 233, 254)'
    || result.route.legend_colors.connection !== 'rgb(255, 255, 255)'
    || result.route.legend_borders.connection !== 'rgb(31, 41, 55)'
    || result.route.step_colors.connection !== 'rgb(255, 255, 255)'
    || result.route.step_borders.connection !== 'rgb(31, 41, 55)'
    || JSON.stringify(result.route.zones) !== JSON.stringify(expectedZones)
    || result.unexpected_error
    || result.browser_errors.length
    || ![200, 302, 303].includes(result.cleanup_status)
  ) {
    process.exitCode = 1;
  }
})();
