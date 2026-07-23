const { launchBrowser } = require('./helpers');
const { execFileSync } = require('child_process');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

function sqlEscape(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "''");
}

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

function createCorruptFixture(name) {
  queryDb(`
    INSERT INTO glpi_plugin_patchpanel_panels
      (entities_id, is_recursive, name, locations_id, plugin_patchpanel_panelmodels_id,
       port_count, \`rows\`, media, is_deleted, date_mod, date_creation)
    VALUES
      (0, 0, '${sqlEscape(name)}', 0, 0, 3, 1, 'copper', 0, NOW(), NOW())
  `);
  const panelId = Number(queryDb(`
    SELECT id
    FROM glpi_plugin_patchpanel_panels
    WHERE name = '${sqlEscape(name)}'
    ORDER BY id DESC
    LIMIT 1
  `));

  queryDb(`
    INSERT INTO glpi_plugin_patchpanel_panelports
      (plugin_patchpanel_panels_id, number, \`row\`, position, label, operational_state,
       media, date_mod, date_creation)
    VALUES
      (${panelId}, 1, 1, 1, 'Broken socket reference', 'active', 'copper', NOW(), NOW()),
      (${panelId}, 2, 1, 2, 'Broken switch port reference', 'active', 'copper', NOW(), NOW()),
      (${panelId}, 3, 1, 3, 'Invalid endpoint type', 'active', 'copper', NOW(), NOW())
  `);
  const portIds = queryDb(`
    SELECT GROUP_CONCAT(id ORDER BY number)
    FROM glpi_plugin_patchpanel_panelports
    WHERE plugin_patchpanel_panels_id = ${panelId}
  `).split(',').map(Number);

  queryDb(`
    INSERT INTO glpi_plugin_patchpanel_portendpoints
      (plugin_patchpanel_panelports_id, side, itemtype, items_id, cables_id,
       cable_color, cable_label, date_mod, date_creation)
    VALUES
      (${portIds[0]}, 'rear', 'Glpi\\\\Socket', 999991001, 0, NULL, NULL, NOW(), NOW()),
      (${portIds[1]}, 'front', 'NetworkPort', 999991002, 0, NULL, NULL, NOW(), NOW()),
      (${portIds[2]}, 'bad', 'Computer', 999991003, 0, NULL, NULL, NOW(), NOW())
  `);

  return { panelId, portIds };
}

function cleanupCorruptFixture(panelId) {
  if (!panelId) {
    return;
  }
  queryDb(`
    DELETE e
    FROM glpi_plugin_patchpanel_portendpoints e
    INNER JOIN glpi_plugin_patchpanel_panelports p
      ON p.id = e.plugin_patchpanel_panelports_id
    WHERE p.plugin_patchpanel_panels_id = ${Number(panelId)}
  `);
  queryDb(`DELETE FROM glpi_plugin_patchpanel_panelports WHERE plugin_patchpanel_panels_id = ${Number(panelId)}`);
  queryDb(`DELETE FROM glpi_plugin_patchpanel_panels WHERE id = ${Number(panelId)}`);
}

(async () => {
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1600, height: 1100 } });
  const browserErrors = [];
  const fixtureName = `PP-CORRUPT-${Date.now()}`;
  let fixture = null;

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
    fixture = createCorruptFixture(fixtureName);

    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    await page.fill('input[name="login_name"]', username);
    await page.fill('input[name="login_password"]', password);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle');

    const brokenPortResponse = await page.goto(
      `${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${fixture.portIds[0]}`,
      { waitUntil: 'networkidle' },
    );
    const brokenPortText = await page.locator('body').innerText();
    const brokenPortSteps = await page.locator('.patchpanel-route-step').evaluateAll(steps =>
      steps.map(step => ({
        text: step.textContent.replace(/\s+/g, ' ').trim(),
        broken: step.classList.contains('patchpanel-route-step-broken'),
        zone: step.getAttribute('data-route-zone'),
      }))
    );

    const routeResponse = await page.goto(
      `${baseUrl}/plugins/patchpanel/front/routes.php?q=${encodeURIComponent(fixtureName)}`,
      { waitUntil: 'networkidle' },
    );
    const routeBody = await page.locator('body').innerText();
    const resultCards = await page.locator('.patchpanel-explorer-result').count();
    const brokenRouteSteps = await page.locator('.patchpanel-explorer-result .patchpanel-route-step-broken').count();

    const healthResponse = await page.goto(`${baseUrl}/plugins/patchpanel/front/health.php`, {
      waitUntil: 'networkidle',
    });
    const healthBody = await page.locator('body').innerText();
    const needsAttentionRows = await page.locator('.patchpanel-health tbody tr').evaluateAll(rows =>
      rows
        .map(row => row.textContent.replace(/\s+/g, ' ').trim())
        .filter(row => row.includes('Needs attention'))
    );

    await page.screenshot({
      path: 'artifacts/patchpanel-corrupt-data-checkpoint.png',
      fullPage: true,
    });

    const result = {
      fixture: {
        panel_id: fixture.panelId,
        port_ids: fixture.portIds,
      },
      broken_port_status: brokenPortResponse.status(),
      broken_port_no_crash:
        brokenPortText.includes('Physical route')
        && brokenPortText.includes('Unavailable or inaccessible object')
        && brokenPortSteps.some(step => step.broken && step.zone === 'connection'),
      route_status: routeResponse.status(),
      route_results: resultCards,
      route_surfaces_broken_references:
        routeBody.includes(fixtureName)
        && routeBody.includes('Unavailable or inaccessible object')
        && brokenRouteSteps >= 2,
      health_status: healthResponse.status(),
      health_warns:
        healthBody.includes('PatchPanel found issues that should be fixed before release.')
        && needsAttentionRows.some(row => row.includes('Broken socket references') && row.includes('1 found'))
        && needsAttentionRows.some(row => row.includes('Broken network port references') && row.includes('1 found'))
        && needsAttentionRows.some(row => row.includes('Invalid endpoint side or type') && row.includes('1 found')),
      needs_attention_rows: needsAttentionRows,
      browser_errors: browserErrors,
    };
    console.log(JSON.stringify(result, null, 2));

    if (
      result.broken_port_status !== 200
      || !result.broken_port_no_crash
      || result.route_status !== 200
      || result.route_results !== 3
      || !result.route_surfaces_broken_references
      || result.health_status !== 200
      || !result.health_warns
      || result.browser_errors.length
    ) {
      process.exitCode = 1;
    }
  } finally {
    cleanupCorruptFixture(fixture?.panelId);
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
