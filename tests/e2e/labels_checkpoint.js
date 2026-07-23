const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';
const rackId = Number(process.env.GLPI_RACK_ID || 1);
const stallRackLayout = process.env.PATCHPANEL_STALL_RACK_LAYOUT === '1';

(async () => {
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });
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

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`, {
    waitUntil: 'networkidle',
  });
  const panelName = `PP-LABEL-${Date.now()}`;
  await page.fill('input[name="name"]', panelName);
  const modelSelect = page.locator('select[name="plugin_patchpanel_panelmodels_id"]');
  await modelSelect.locator('xpath=following-sibling::span[contains(@class,"select2")]').click();
  await page.locator('.select2-search__field').last().fill('48-port copper, 2U');
  await page.locator('.select2-results__option', { hasText: '48-port copper, 2U' }).last().click();
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');
  const panelId = Number(new URL(page.url()).searchParams.get('id'));

  await page.goto(`${baseUrl}/front/rack.form.php?id=${rackId}&forcetab=Item_Rack%241`, {
    waitUntil: 'networkidle',
  });
  await page.locator('.rack_front .cell_add').first().click();
  const rackItemType = page.locator('select[name="itemtype"]');
  await rackItemType.waitFor({ state: 'visible' });
  const rackItemTypeVisible = await rackItemType.isVisible();
  const patchPanelTypeVisible = await rackItemType
    .locator('option[value="PluginPatchpanelPanel"]')
    .count() === 1;
  await rackItemType.selectOption('PluginPatchpanelPanel');
  await page.waitForLoadState('networkidle');
  const rackItem = page.locator('select[name="items_id"]');
  await page.locator('#items_id .select2-selection').click();
  await page.locator('.select2-search__field').last().fill(panelName);
  const rackResult = page.locator('.select2-results__option', { hasText: panelName }).last();
  await rackResult.waitFor({ state: 'visible' });
  const rackItemVisible = await rackResult.isVisible();
  await rackResult.click();
  await page.locator('select[name="position"]').waitFor();
  await page.waitForFunction(() => document.querySelector('select[name="position"]')?.value === '41');
  const correctedRackPosition = await page.locator('select[name="position"]').inputValue();
  if (stallRackLayout) {
    await page.route('**/plugins/patchpanel/front/panellayout.php?**', async (route) => {
      await new Promise(resolve => setTimeout(resolve, 5000));
      await route.continue();
    });
  }
  const rackSubmitStartedAt = Date.now();
  await page.locator('button[name="add"], input[name="add"]').last().click();
  await page.waitForLoadState('networkidle');
  const rackSubmitDurationMs = Date.now() - rackSubmitStartedAt;
  const rackPlacementSucceeded = !(await page.locator('body').innerText())
    .includes('Item is out of rack bounds');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/labels.php?panel_id=${panelId}`, {
    waitUntil: 'networkidle',
  });

  const labels = page.locator('.patchpanel-label');
  const labelCount = await labels.count();
  const firstQr = await labels.first().locator('img').getAttribute('src');
  const firstText = await labels.first().innerText();

  await page.fill('input[name="from_port"]', '2');
  await page.fill('input[name="to_port"]', '3');
  await page.locator('button[type="submit"]', { hasText: 'Generate labels' }).click();
  await page.waitForLoadState('networkidle');
  const rangedCount = await page.locator('.patchpanel-label').count();
  const rangedText = await page.locator('.patchpanel-label-sheet').innerText();

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  const token = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  const softDelete = await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panel.form.php`,
    {
      form: {
        id: String(panelId),
        delete: '1',
        _glpi_csrf_token: token,
      },
      maxRedirects: 0,
    },
  );
  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  const purgeToken = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  const cleanup = await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panel.form.php`,
    {
      form: {
        id: String(panelId),
        purge: '1',
        _glpi_csrf_token: purgeToken,
      },
      maxRedirects: 0,
    },
  );
  const cleanupCheck = await page.request.get(
    `${baseUrl}/plugins/patchpanel/front/panellayout.php?id=${panelId}&rack_id=${rackId}`,
  );

  const result = {
    rack_itemtype_visible: rackItemTypeVisible,
    patchpanel_type_visible: patchPanelTypeVisible,
    rack_item_visible: rackItemVisible,
    corrected_rack_position: correctedRackPosition,
    rack_placement_succeeded: rackPlacementSucceeded,
    stalled_layout_did_not_block_submit:
      !stallRackLayout || rackSubmitDurationMs < 5000,
    rack_submit_duration_ms: rackSubmitDurationMs,
    label_count: labelCount,
    qr_is_png_data_uri: firstQr?.startsWith('data:image/png;base64,') || false,
    first_label_has_panel_and_port:
      firstText.includes(panelName) && firstText.includes('Port 1'),
    ranged_count: rangedCount,
    range_is_exact:
      rangedText.includes('Port 2')
      && rangedText.includes('Port 3')
      && !rangedText.includes('Port 1')
      && !rangedText.includes('Port 4'),
    cleanup_statuses: [softDelete.status(), cleanup.status()],
    cleanup_verified: cleanupCheck.status() === 404,
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    !result.rack_itemtype_visible
    || !result.patchpanel_type_visible
    || !result.rack_item_visible
    || result.corrected_rack_position !== '41'
    || !result.rack_placement_succeeded
    || !result.stalled_layout_did_not_block_submit
    || result.label_count !== 48
    || !result.qr_is_png_data_uri
    || !result.first_label_has_panel_and_port
    || result.ranged_count !== 2
    || !result.range_is_exact
    || result.cleanup_statuses.some(status => ![200, 302, 303].includes(status))
    || !result.cleanup_verified
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})();
