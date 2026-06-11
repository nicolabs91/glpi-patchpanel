const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

(async () => {
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
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
  const panelName = `PP-AUDIT-${Date.now()}`;
  await page.fill('input[name="name"]', panelName);
  await page.fill('input[name="port_count"]', '2');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');
  const panelId = Number(new URL(page.url()).searchParams.get('id'));

  await page.locator('a, button').filter({ hasText: /Visual panel/i }).first().click();
  const firstPortHref = await page.locator('.patchpanel-port').first().getAttribute('href');
  await page.goto(new URL(firstPortHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  await page.fill('input[name="label"]', 'Audited manual label');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  await page.locator('a, button').filter({ hasText: /Visual panel/i }).first().click();
  await page.fill('input[name="from_port"]', '2');
  await page.fill('input[name="to_port"]', '2');
  await page.selectOption('select[name="operational_state"]', 'reserved');
  await page.locator('button[name="bulk_update"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/audit.php?panel_id=${panelId}`, {
    waitUntil: 'networkidle',
  });
  const body = await page.locator('body').innerText();
  const eventRows = await page.locator('tbody tr').count();
  const manualRow = page.locator('tbody tr', { hasText: 'manual' });
  const manualDetails = manualRow.locator('details');
  await manualDetails.locator('summary').click();
  const manualDetailText = await manualDetails.innerText();
  const bulkRow = page.locator('tbody tr', { hasText: 'bulk' });
  const bulkDetails = bulkRow.locator('details');
  await bulkDetails.locator('summary').click();
  const bulkDetailText = await bulkDetails.innerText();

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
    event_rows: eventRows,
    manual_event:
      body.includes('manual')
      && body.includes('Updated panel port 1')
      && manualDetailText.includes('Audited manual label'),
    bulk_event:
      body.includes('bulk')
      && body.includes('Bulk-updated port 2'),
    user_visible: body.includes('glpi'),
    snapshots_visible:
      manualDetailText.includes('Before')
      && manualDetailText.includes('After')
      && manualDetailText.includes('label')
      && bulkDetailText.includes('operational_state'),
    cleanup_status: cleanup.status(),
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    result.event_rows !== 2
    || !result.manual_event
    || !result.bulk_event
    || !result.user_visible
    || !result.snapshots_visible
    || ![200, 302, 303].includes(result.cleanup_status)
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})();
