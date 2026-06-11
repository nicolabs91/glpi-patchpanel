const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

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
  const panelName = `PP-V2-MODEL-BULK-${Date.now()}`;
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
  await selectValue(page, 'rear_items_id', 90, 'Kamer 0103 Wall outlet');
  await selectValue(page, 'front_items_id', 228, 'SW-L1-IDF-A - Port 03');
  await page.locator('button[name="update"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  await visualTab.click();
  await page.locator('.patchpanel-bulk').waitFor({ state: 'visible' });
  await page.fill('input[name="from_port"]', '3');
  await page.fill('input[name="to_port"]', '5');
  await page.fill('input[name="label_pattern"]', 'Rack A-{n:02}');
  await selectValue(page, 'operational_state', 'reserved', 'Reserved');
  await selectValue(page, 'media', 'fiber-mm', 'Multimode fiber');
  await page.locator('button[name="bulk_update"]').click();
  await page.waitForLoadState('networkidle');

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

  const csrfToken = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  const invalidResponse = await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panelport.bulk.php`,
    {
      form: {
        plugin_patchpanel_panels_id: String(panelId),
        from_port: '5',
        to_port: '3',
        label_pattern: 'BROKEN-{n}',
        operational_state: 'fault',
        media: 'copper',
        bulk_update: '1',
        _glpi_csrf_token: csrfToken,
      },
    }
  );

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${port3Id}`, {
    waitUntil: 'networkidle',
  });
  const afterInvalid = {
    label: await page.locator('input[name="label"]').inputValue(),
    state: await page.locator('select[name="operational_state"]').inputValue(),
    media: await page.locator('select[name="media"]').inputValue(),
  };

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
  await page.locator('input[name="apply_model"][value="1"]').check();
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
  const overrideToken = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  const overrideCleanup = await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panel.form.php`,
    {
      form: {
        id: String(overridePanelId),
        purge: '1',
        _glpi_csrf_token: overrideToken,
      },
      maxRedirects: 0,
    }
  );

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
    bulk_result: port3,
    route_preserved:
      routeBody.includes('Kamer 0103 Wall outlet')
      && routeBody.includes('SW-L1-IDF-A'),
    invalid_status: invalidResponse.status(),
    rollback_preserved: JSON.stringify(afterInvalid) === JSON.stringify({
      label: 'Rack A-03',
      state: 'reserved',
      media: 'fiber-mm',
    }),
    cleanup_status: cleanupResponse.status(),
    existing_panel_model_override: overrideApplied,
    override_cleanup_status: overrideCleanup.status(),
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
    || result.bulk_result.label !== 'Rack A-03'
    || result.bulk_result.state !== 'reserved'
    || result.bulk_result.media !== 'fiber-mm'
    || result.bulk_result.rear !== '90'
    || result.bulk_result.front !== '228'
    || !result.route_preserved
    || !result.rollback_preserved
    || ![200, 302, 303].includes(result.cleanup_status)
    || result.existing_panel_model_override.port_count !== '24'
    || result.existing_panel_model_override.rows !== '1'
    || result.existing_panel_model_override.media !== 'fiber-mm'
    || ![200, 302, 303].includes(result.override_cleanup_status)
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})();
