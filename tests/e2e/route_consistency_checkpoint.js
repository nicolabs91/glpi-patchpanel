const { launchBrowser } = require('./helpers');
const { execFileSync } = require('child_process');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

const TEST_PANEL_PORT_ID = 2605;
const TEST_SOCKET_ID = 86;
const TEST_SOCKET_PORT_ID = 217;
const TEST_FRONT_PORT_ID = 224;
const TEST_ACCESS_SWITCH_ID = 21;
const TEST_PANEL_NAME = 'PP-L1-IDF-A - Guest room outlets 0101-0124';

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

function resetAp001Route() {
  queryDb(
    `UPDATE glpi_sockets
     SET itemtype = 'NetworkEquipment', items_id = 1, networkports_id = ${TEST_SOCKET_PORT_ID}
     WHERE id = ${TEST_SOCKET_ID}`
  );
  queryDb(
    `DELETE FROM glpi_networkports_networkports
     WHERE networkports_id_1 IN (${TEST_SOCKET_PORT_ID}, ${TEST_FRONT_PORT_ID})
        OR networkports_id_2 IN (${TEST_SOCKET_PORT_ID}, ${TEST_FRONT_PORT_ID})`
  );
  queryDb(
    `INSERT INTO glpi_networkports_networkports (networkports_id_1, networkports_id_2)
     SELECT ${TEST_FRONT_PORT_ID}, id
     FROM glpi_networkports
     WHERE itemtype = 'PluginPatchpanelPanelPort'
       AND items_id = ${TEST_PANEL_PORT_ID}
       AND is_deleted = 0
     LIMIT 1`
  );
  queryDb(
    `DELETE FROM glpi_plugin_patchpanel_portendpoints
     WHERE itemtype = 'Glpi\\\\Socket'
       AND items_id = ${TEST_SOCKET_ID}
       AND plugin_patchpanel_panelports_id <> ${TEST_PANEL_PORT_ID}`
  );
  queryDb(
    `DELETE FROM glpi_plugin_patchpanel_portendpoints
     WHERE plugin_patchpanel_panelports_id = ${TEST_PANEL_PORT_ID}`
  );
  queryDb(
    `INSERT INTO glpi_plugin_patchpanel_portendpoints
       (plugin_patchpanel_panelports_id, side, itemtype, items_id, cables_id,
        cable_color, cable_label, date_mod, date_creation)
     VALUES
       (${TEST_PANEL_PORT_ID}, 'rear', 'Glpi\\\\Socket', ${TEST_SOCKET_ID}, 0, NULL, NULL, NOW(), NOW()),
       (${TEST_PANEL_PORT_ID}, 'front', 'NetworkPort', ${TEST_FRONT_PORT_ID}, 0, NULL, NULL, NOW(), NOW())`
  );
}

async function login(page) {
  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.fill('input[name="login_name"]', username);
  await page.fill('input[name="login_password"]', password);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');
}

async function readRouteSteps(locator) {
  return locator.locator('.patchpanel-route-step').evaluateAll(steps =>
    steps.map(step => ({
      text: step.textContent.replace(/\s+/g, ' ').trim(),
      zone: step.getAttribute('data-route-zone'),
      broken: step.classList.contains('patchpanel-route-step-broken'),
      href: step.getAttribute('href') || '',
    }))
  );
}

function comparableSteps(steps) {
  return steps.map(step => ({
    text: step.text,
    zone: step.zone,
    broken: step.broken,
    href: step.href.replace(/^https?:\/\/[^/]+/, ''),
  }));
}

(async () => {
  resetAp001Route();

  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1700, height: 1200 } });
  const browserErrors = [];

  page.on('pageerror', error => browserErrors.push(`pageerror: ${error.message}`));
  page.on('console', message => {
    if (message.type() === 'error') {
      browserErrors.push(`console: ${message.text()}`);
    }
  });
  page.on('response', response => {
    if (response.status() >= 500) {
      browserErrors.push(`${response.status()} ${response.url()}`);
    }
  });

  try {
    await login(page);

    await page.goto(`${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${TEST_PANEL_PORT_ID}`, {
      waitUntil: 'networkidle',
    });
    const portSteps = await readRouteSteps(page.locator('.patchpanel-route').first());

    await page.goto(`${baseUrl}/plugins/patchpanel/front/routes.php?q=${encodeURIComponent(TEST_PANEL_NAME)}`, {
      waitUntil: 'networkidle',
    });
    const explorerCard = page
      .locator('.patchpanel-explorer-result')
      .filter({ hasText: `${TEST_PANEL_NAME} / #1` })
      .first();
    const explorerSteps = await readRouteSteps(explorerCard);

    await page.goto(`${baseUrl}/front/networkport.form.php?id=${TEST_SOCKET_PORT_ID}`, {
      waitUntil: 'networkidle',
    });
    const endpointTab = page.locator('a, button').filter({ hasText: /Patch panel routes/i }).first();
    const endpointTabUsesPanelIcon = await endpointTab.locator('i.ti-layout-grid').count() === 1;
    await endpointTab.click();
    await page.waitForTimeout(1000);
    const endpointCard = page
      .locator('.patchpanel-endpoint-route')
      .filter({ hasText: `${TEST_PANEL_NAME} / #1` })
      .first();
    const endpointSteps = await readRouteSteps(endpointCard);

    await page.goto(
      `${baseUrl}/front/networkequipment.form.php?id=${TEST_ACCESS_SWITCH_ID}&forcetab=NetworkPort$1`,
      { waitUntil: 'networkidle' },
    );
    const visiblePanelPortLink = page.locator(
      `a[href*="/plugins/patchpanel/front/panelport.form.php?id=${TEST_PANEL_PORT_ID}"]`,
    ).first();
    await visiblePanelPortLink.waitFor({ state: 'visible' });
    const nativeConnectionCell = visiblePanelPortLink.locator('xpath=ancestor::td[1]');
    const visibleShadowPortLinks = await nativeConnectionCell
      .locator('a[href*="/front/networkport.form.php"]')
      .count();

    const impactHref = `/plugins/patchpanel/front/routes.php?impact_type=NetworkEquipment&impact_id=${TEST_ACCESS_SWITCH_ID}`;
    await page.goto(new URL(impactHref, baseUrl).toString(), { waitUntil: 'networkidle' });
    const impactCard = page
      .locator('.patchpanel-explorer-result')
      .filter({ hasText: `${TEST_PANEL_NAME} / #1` })
      .first();
    const impactSteps = await readRouteSteps(impactCard);

    await page.screenshot({
      path: 'artifacts/patchpanel-route-consistency-checkpoint.png',
      fullPage: true,
    });

    const portComparable = comparableSteps(portSteps);
    const explorerComparable = comparableSteps(explorerSteps);
    const endpointComparable = comparableSteps(endpointSteps);
    const impactComparable = comparableSteps(impactSteps);

    const serialized = {
      port: JSON.stringify(portComparable),
      explorer: JSON.stringify(explorerComparable),
      endpoint: JSON.stringify(endpointComparable),
      impact: JSON.stringify(impactComparable),
    };
    const allMatch =
      serialized.port === serialized.explorer
      && serialized.port === serialized.endpoint
      && serialized.port === serialized.impact;

    const expectedOrder = [
      'NLH-R0101-TV01 - Guest room television · eth0 - NLH-R0101-TV01',
      'NLH-R0101-WA-TV01 - Room 0101 TV wall outlet',
      TEST_PANEL_NAME,
      'Patch port 01',
      'NLH-F01-IDF-A-SW01 01',
    ];

    const result = {
      counts: {
        port: portComparable.length,
        explorer: explorerComparable.length,
        endpoint: endpointComparable.length,
        impact: impactComparable.length,
      },
      all_surfaces_match: allMatch,
      endpoint_tab_uses_panel_icon: endpointTabUsesPanelIcon,
      native_connection_has_one_panel_port: await visiblePanelPortLink.count() === 1,
      native_connection_hides_shadow_port: visibleShadowPortLinks === 0,
      expected_order:
        expectedOrder.every((label, index) => portComparable[index]?.text === label),
      endpoint_not_repeated_as_upstream:
        !portComparable.slice(5).some(step =>
          step.href === '/front/networkequipment.form.php?id=1'
          || step.text.includes('NLH-R0101-TV01')
        ),
      downlinks_not_misclassified_as_upstream:
        !portComparable.slice(5).some(step =>
          step.text.includes('Guest room television')
          || step.href === '/front/networkequipment.form.php?id=3'
        ),
      impact_href: impactHref,
      port_steps: portComparable,
      explorer_steps: explorerComparable,
      endpoint_steps: endpointComparable,
      impact_steps: impactComparable,
      browser_errors: browserErrors,
    };
    console.log(JSON.stringify(result, null, 2));

    if (
      !result.all_surfaces_match
      || !result.endpoint_tab_uses_panel_icon
      || !result.native_connection_has_one_panel_port
      || !result.native_connection_hides_shadow_port
      || !result.expected_order
      || !result.endpoint_not_repeated_as_upstream
      || !result.downlinks_not_misclassified_as_upstream
      || result.counts.port < 5
      || result.browser_errors.length
    ) {
      process.exitCode = 1;
    }
  } finally {
    await browser.close();
    resetAp001Route();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
