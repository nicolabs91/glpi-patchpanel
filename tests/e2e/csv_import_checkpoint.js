const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';
const headers = [
  'panel',
  'port',
  'label',
  'operational_state',
  'media',
  'rear_socket_id',
  'front_networkport_id',
  'rear_cable_color',
  'front_cable_color',
  'cable_id',
].join(',');

async function uploadCsv(page, content, filename) {
  await page.setInputFiles('input[name="csv_file"]', {
    name: filename,
    mimeType: 'text/csv',
    buffer: Buffer.from(content, 'utf8'),
  });
  await page.locator('button[name="preview_csv"]').click();
  await page.waitForLoadState('networkidle');
}

(async () => {
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1700, height: 1200 } });
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
  const panelName = `PP-CSV-${Date.now()}`;
  await page.fill('input[name="name"]', panelName);
  await page.fill('input[name="port_count"]', '2');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');
  const panelId = Number(new URL(page.url()).searchParams.get('id'));

  await page.goto(`${baseUrl}/plugins/patchpanel/front/csvimport.php`, {
    waitUntil: 'networkidle',
  });
  const duplicateHeaderCsv = [
    headers.replace('panel,port', 'panel,panel,port'),
    `${panelName},${panelName},1,CSV Port 01,reserved,fiber-mm,24,225,#0d6efd,#ffc107,0`,
  ].join('\n');
  await uploadCsv(page, duplicateHeaderCsv, 'duplicate-header.csv');
  const duplicateHeaderBody = await page.locator('body').innerText();
  const duplicateHeaderApplyButtons = await page.locator('button[name="apply_csv"]').count();

  await page.goto(`${baseUrl}/plugins/patchpanel/front/csvimport.php`, {
    waitUntil: 'networkidle',
  });
  const invalidCsv = [
    headers,
    `${panelName},1,CSV Port 01,reserved,fiber-mm,24,225,#0d6efd,#ffc107,0`,
    `${panelName},2,CSV Port 02,active,copper,24,227,#198754,#dc3545,0`,
  ].join('\n');
  await uploadCsv(page, invalidCsv, 'invalid-duplicate.csv');
  const invalidBody = await page.locator('body').innerText();
  const invalidApplyButtons = await page.locator('button[name="apply_csv"]').count();

  await page.goto(`${baseUrl}/plugins/patchpanel/front/csvimport.php`, {
    waitUntil: 'networkidle',
  });
  const validCsv = [
    headers,
    `${panelName},1,CSV Port 01,reserved,fiber-mm,24,225,#0d6efd,#ffc107,0`,
    `${panelName},2,CSV Port 02,active,copper,25,227,#198754,#dc3545,0`,
  ].join('\n');
  await uploadCsv(page, validCsv, 'valid-import.csv');
  const previewBody = await page.locator('body').innerText();
  const readyRows = await page.locator('tbody tr', { hasText: 'Ready' }).count();
  await page.locator('input[name="confirm_import"]').check();
  await page.locator('button[name="apply_csv"]').click();
  await page.waitForLoadState('networkidle');
  const appliedBody = await page.locator('body').innerText();

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  await page.locator('a, button').filter({ hasText: /Visual panel/i }).first().click();
  const firstPortHref = await page.locator('.patchpanel-port').first().getAttribute('href');
  await page.goto(new URL(firstPortHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  const imported = {
    label: await page.locator('input[name="label"]').inputValue(),
    state: await page.locator('select[name="operational_state"]').inputValue(),
    media: await page.locator('select[name="media"]').inputValue(),
    rear: await page.locator('select[name="rear_items_id"]').inputValue(),
    front: await page.locator('select[name="front_items_id"]').inputValue(),
    route: await page.locator('.patchpanel-route').evaluate(element =>
      element.textContent.replace(/\s+/g, ' ').trim()
    ),
  };

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  const blockedPurgeToken = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panel.form.php`,
    {
      form: {
        id: String(panelId),
        purge: '1',
        _glpi_csrf_token: blockedPurgeToken,
      },
      maxRedirects: 0,
    },
  );
  const stillExists = await page.goto(
    `${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`,
    { waitUntil: 'networkidle' },
  );
  const stillExistsBody = await page.locator('body').innerText();
  const panelStillExists = stillExists.status() === 200
    && stillExistsBody.includes(panelName);

  await page.goto(new URL(firstPortHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  await page.fill('input[name="label"]', 'Manual override');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/csvimport.php`, {
    waitUntil: 'networkidle',
  });
  let rollbackForm = page.locator('form').filter({
    has: page.locator('button[name="rollback_batch"]'),
  }).first();
  const batch = await rollbackForm.locator('input[name="batch_uuid"]').inputValue();
  await rollbackForm.locator('button[name="rollback_batch"]').click();
  await page.waitForLoadState('networkidle');
  const blockedRollbackBody = await page.locator('body').innerText();

  await page.goto(new URL(firstPortHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  await page.fill('input[name="label"]', 'CSV Port 01');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/csvimport.php`, {
    waitUntil: 'networkidle',
  });
  rollbackForm = page.locator('form').filter({
    has: page.locator('button[name="rollback_batch"]'),
  }).first();
  await rollbackForm.locator('button[name="rollback_batch"]').click();
  await page.waitForLoadState('networkidle');
  const rollbackBody = await page.locator('body').innerText();

  await page.goto(new URL(firstPortHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  const rolledBack = {
    label: await page.locator('input[name="label"]').inputValue(),
    state: await page.locator('select[name="operational_state"]').inputValue(),
    media: await page.locator('select[name="media"]').inputValue(),
    rear: await page.locator('select[name="rear_items_id"]').inputValue(),
    front: await page.locator('select[name="front_items_id"]').inputValue(),
  };

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  const token = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  const cleanup = await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panel.form.php`,
    {
      form: {
        id: String(panelId),
        purge: '1',
        _glpi_csrf_token: token,
      },
      maxRedirects: 0,
    },
  );

  const result = {
    duplicate_header_blocked:
      duplicateHeaderBody.includes('Duplicate CSV columns: panel')
      && duplicateHeaderApplyButtons === 0,
    duplicate_blocked:
      invalidBody.includes('also used on CSV line 2') && invalidApplyButtons === 0,
    preview_ready_rows: readyRows,
    preview_has_changes:
      previewBody.includes('CSV Port 01') && previewBody.includes('front: #225'),
    apply_message: appliedBody.includes('Imported 2 CSV rows in rollback batch'),
    batch,
    imported: {
      label: imported.label,
      state: imported.state,
      media: imported.media,
      rear: imported.rear,
      front: imported.front,
      complete_route:
        imported.route.includes('NLH-L1-K101-A')
        && imported.route.includes('NLH-F01-IDF-B-SW01')
        && imported.route.includes('NLH-MDF-FW01'),
    },
    active_batch_blocks_panel_purge: panelStillExists,
    changed_port_blocks_rollback:
      blockedRollbackBody.includes('Rollback stopped because an imported port was changed after the import.'),
    rollback_message: rollbackBody.includes('Rolled back 2 CSV changes.'),
    rollback_no_active_batch: rollbackBody.includes('No active CSV import batch exists.'),
    rolled_back: rolledBack,
    cleanup_status: cleanup.status(),
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    !result.duplicate_blocked
    || !result.duplicate_header_blocked
    || result.preview_ready_rows !== 2
    || !result.preview_has_changes
    || !result.apply_message
    || !/^[a-f0-9]{32}$/.test(result.batch)
    || result.imported.label !== 'CSV Port 01'
    || result.imported.state !== 'reserved'
    || result.imported.media !== 'fiber-mm'
    || result.imported.rear !== '24'
    || result.imported.front !== '225'
    || !result.imported.complete_route
    || !result.active_batch_blocks_panel_purge
    || !result.changed_port_blocks_rollback
    || !result.rollback_message
    || !result.rollback_no_active_batch
    || result.rolled_back.label !== 'Patch port 01'
    || result.rolled_back.state !== 'active'
    || result.rolled_back.media !== 'copper'
    || result.rolled_back.rear !== '0'
    || result.rolled_back.front !== '0'
    || ![200, 302, 303].includes(result.cleanup_status)
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})();
