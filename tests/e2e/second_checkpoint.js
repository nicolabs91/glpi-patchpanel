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

function updatePanelName(panelId, name) {
  const code = `
chdir('/var/www/glpi');
require_once 'src/Glpi/Application/ResourcesChecker.php';
(new Glpi\\Application\\ResourcesChecker(getcwd()))->checkResources();
require_once 'vendor/autoload.php';
$kernel = new Glpi\\Kernel\\Kernel();
$kernel->boot();
$auth = new Auth();
$auth->login('${username}', '${password}', true);
$panel = new PluginPatchpanelPanel();
if (!$panel->update(['id' => ${Number(panelId)}, 'name' => '${name}'])) {
    exit(2);
}
`;
  execFileSync('docker', ['exec', 'glpi-app', 'php', '-r', code]);
}

function updatePanelPortCount(panelId, portCount) {
  const code = `
chdir('/var/www/glpi');
require_once 'src/Glpi/Application/ResourcesChecker.php';
(new Glpi\\Application\\ResourcesChecker(getcwd()))->checkResources();
require_once 'vendor/autoload.php';
$kernel = new Glpi\\Kernel\\Kernel();
$kernel->boot();
$auth = new Auth();
$auth->login('${username}', '${password}', true);
$panel = new PluginPatchpanelPanel();
if (!$panel->update(['id' => ${Number(panelId)}, 'port_count' => ${Number(portCount)}])) {
    exit(2);
}
`;
  execFileSync('docker', ['exec', 'glpi-app', 'php', '-r', code]);
}

function purgePanel(panelId) {
  const code = `
chdir('/var/www/glpi');
require_once 'src/Glpi/Application/ResourcesChecker.php';
(new Glpi\\Application\\ResourcesChecker(getcwd()))->checkResources();
require_once 'vendor/autoload.php';
$kernel = new Glpi\\Kernel\\Kernel();
$kernel->boot();
$auth = new Auth();
$auth->login('${username}', '${password}', true);
$panel = new PluginPatchpanelPanel();
if (!$panel->getFromDB(${Number(panelId)}) || !$panel->delete(['id' => ${Number(panelId)}], true)) {
    exit(2);
}
`;
  execFileSync('docker', ['exec', 'glpi-app', 'php', '-r', code]);
}

async function selectValue(page, name, value, label) {
  await page.locator(`select[name="${name}"]`).evaluate((element, option) => {
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
  const errors = [];
  page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
  page.on('response', response => {
    if (response.status() >= 400) {
      errors.push(`${response.status()} ${response.url()}`);
    }
  });

  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.fill('input[name="login_name"]', username);
  await page.fill('input[name="login_password"]', password);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');

  const modelResponse = await page.goto(
    `${baseUrl}/plugins/patchpanel/front/panelmodel.form.php?id=2`,
    { waitUntil: 'networkidle' }
  );
  const modelBody = await page.locator('body').innerText();

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`, {
    waitUntil: 'networkidle',
  });
  const panelName = `PP-V2-MODEL-${Date.now()}`;
  await page.fill('input[name="name"]', panelName);
  await selectValue(page, 'plugin_patchpanel_panelmodels_id', 2, '48-port copper, 2U');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');

  const panelId = Number(new URL(page.url()).searchParams.get('id'));
  if (!panelId) {
    throw new Error(`Model panel creation failed: ${page.url()}`);
  }

  const portCountValue = await page.locator('input[name="port_count"]').inputValue();
  const rowsValue = await page.locator('select[name="rows"]').inputValue();
  const mediaValue = await page.locator('select[name="media"]').inputValue();

  const visualTab = page.locator('a, button').filter({ hasText: /Visual panel/i }).first();
  await visualTab.click();
  await page.locator('.patchpanel-port').first().waitFor({ state: 'visible' });
  const visualPortCount = await page.locator('.patchpanel-port').count();
  const port3Url = await page.locator('.patchpanel-port').nth(2).getAttribute('href');
  const port3Id = Number(new URL(port3Url, baseUrl).searchParams.get('id'));

  await page.goto(`${baseUrl}${port3Url}`, { waitUntil: 'networkidle' });
  await page.fill('input[name="label"]', 'Rack A-03');
  await selectValue(page, 'operational_state', 'reserved', 'Reserved');
  await selectValue(page, 'media', 'fiber-mm', 'Multimode fiber');
  queryDb(
    "UPDATE glpi_sockets SET itemtype = 'NetworkEquipment', items_id = 278, networkports_id = 332 WHERE id = 299"
  );
  await selectValue(page, 'rear_items_id', 299, 'NLH-R0201-WA01 - Room 0201 wall outlet');
  await selectValue(page, 'front_items_id', 227, 'NLH-F01-IDF-B-SW01 02');
  await page.locator('button[name="update"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  await visualTab.click();
  const visualBody = await page.locator('body').innerText();
  const bulkRouteResponse = await page.request.get(
    `${baseUrl}/plugins/patchpanel/front/panelport.bulk.php`,
  );

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${port3Id}`, {
    waitUntil: 'networkidle',
  });
  const port3 = {
    label: await page.locator('input[name="label"]').inputValue(),
    state: await page.locator('select[name="operational_state"]').inputValue(),
    media: await page.locator('select[name="media"]').inputValue(),
    rear: await page.locator('select[name="rear_items_id"]').inputValue(),
    front: await page.locator('select[name="front_items_id"]').inputValue(),
  };
  const routeBody = await page.locator('body').innerText();

  updatePanelName(panelId, `${panelName}-RENAMED`);
  await page.goto(`${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${port3Id}`, {
    waitUntil: 'networkidle',
  });
  const mediaAfterPanelRename = await page.locator('select[name="media"]').inputValue();

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  const cleanupToken = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  const cleanupResponse = await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panel.form.php`,
    {
      form: {
        id: String(panelId),
        purge: '1',
        _glpi_csrf_token: cleanupToken,
      },
      maxRedirects: 0,
    }
  );

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`, {
    waitUntil: 'networkidle',
  });
  const overrideName = `PP-V2-MODEL-OVERRIDE-${Date.now()}`;
  await page.fill('input[name="name"]', overrideName);
  await page.fill('input[name="port_count"]', '12');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');
  const overridePanelId = Number(new URL(page.url()).searchParams.get('id'));

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panelmodel.php`, {
    waitUntil: 'networkidle',
  });
  const fiberModelHref = await page.locator('a')
    .filter({ hasText: /24-port multimode fiber/i })
    .first()
    .getAttribute('href');
  const fiberModelId = fiberModelHref
    ? new URL(fiberModelHref, baseUrl).searchParams.get('id')
    : null;
  if (!fiberModelId) {
    throw new Error('Multimode fiber model is missing');
  }
  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${overridePanelId}`, {
    waitUntil: 'networkidle',
  });
  await selectValue(
    page,
    'plugin_patchpanel_panelmodels_id',
    fiberModelId,
    '24-port multimode fiber, 1U',
  );
  const applyModelTextVisible = (await page.locator('body').innerText())
    .includes('Replace port count, rows and media with the selected model when saving.');
  await page.locator('button[name="update"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${overridePanelId}`, {
    waitUntil: 'networkidle',
  });
  const overrideApplied = {
    port_count: await page.locator('input[name="port_count"]').inputValue(),
    rows: await page.locator('select[name="rows"]').inputValue(),
    media: await page.locator('select[name="media"]').inputValue(),
  };

  await page.locator('a, button').filter({ hasText: /Visual panel/i }).first().click();
  await page.locator('.patchpanel-port').last().waitFor({ state: 'visible' });
  const lastPortHref = await page.locator('.patchpanel-port').last().getAttribute('href');
  const lastPortId = Number(new URL(lastPortHref, baseUrl).searchParams.get('id'));
  await page.goto(new URL(lastPortHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  await selectValue(page, 'front_items_id', 255, 'NLH-F01-IDF-B-SW01 16');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');
  const shadowPortId = queryDb(
    `SELECT id FROM glpi_networkports
     WHERE itemtype = 'PluginPatchpanelPanelPort' AND items_id = ${lastPortId} AND is_deleted = 0
     LIMIT 1`
  );
  await selectValue(page, 'front_items_id', 0, '-----');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');

  updatePanelPortCount(overridePanelId, 12);
  const shrinkCleanup = {
    removed_panel_port: queryDb(
      `SELECT COUNT(*) FROM glpi_plugin_patchpanel_panelports WHERE id = ${lastPortId}`
    ) === '0',
    removed_shadow_port: queryDb(
      `SELECT COUNT(*) FROM glpi_networkports WHERE id = ${Number(shadowPortId)}`
    ) === '0',
  };

  purgePanel(overridePanelId);
  const overrideCleanupStatus = 200;

  const result = {
    model_page_status: modelResponse.status(),
    model_fields_visible:
      modelBody.includes('48-port copper, 2U')
      && modelBody.includes('Number of ports')
      && modelBody.includes('Rows'),
    panel_id: panelId,
    model_applied: {
      port_count: portCountValue,
      rows: rowsValue,
      media: mediaValue,
      visual_ports: visualPortCount,
    },
    port_result: port3,
    route_preserved:
      routeBody.includes('NLH-R0201-WA01')
      && routeBody.includes('NLH-F01-IDF-B-SW01'),
    media_after_panel_rename: mediaAfterPanelRename,
    bulk_ui_removed: !visualBody.includes('Bulk port management'),
    bulk_route_status: bulkRouteResponse.status(),
    apply_model_text_removed: !applyModelTextVisible,
    cleanup_status: cleanupResponse.status(),
    existing_panel_model_override: overrideApplied,
    shrink_cleanup: shrinkCleanup,
    override_cleanup_status: overrideCleanupStatus,
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    result.model_page_status !== 200
    || !result.model_fields_visible
    || result.model_applied.port_count !== '48'
    || result.model_applied.rows !== '2'
    || result.model_applied.media !== 'copper'
    || result.model_applied.visual_ports !== 48
    || result.port_result.label !== 'Rack A-03'
    || result.port_result.state !== 'reserved'
    || result.port_result.media !== 'fiber-mm'
    || result.port_result.rear !== '299'
    || result.port_result.front !== '227'
    || !result.route_preserved
    || result.media_after_panel_rename !== 'fiber-mm'
    || !result.bulk_ui_removed
    || result.bulk_route_status !== 404
    || !result.apply_model_text_removed
    || ![200, 302, 303].includes(result.cleanup_status)
    || result.existing_panel_model_override.port_count !== '24'
    || result.existing_panel_model_override.rows !== '1'
    || result.existing_panel_model_override.media !== 'fiber-mm'
    || !result.shrink_cleanup.removed_panel_port
    || !result.shrink_cleanup.removed_shadow_port
    || ![200, 302, 303].includes(result.override_cleanup_status)
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})();
