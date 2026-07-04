const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

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
  await page.fill('input[name="port_count"]', '4');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');
  const panelId = Number(new URL(page.url()).searchParams.get('id'));

  const body = await page.locator('body').innerText();
  const rackTabVisible = body.includes('Rack');

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
    rack_tab_visible: rackTabVisible,
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
    cleanup_status: cleanup.status(),
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    !result.rack_tab_visible
    || result.label_count !== 4
    || !result.qr_is_png_data_uri
    || !result.first_label_has_panel_and_port
    || result.ranged_count !== 2
    || !result.range_is_exact
    || ![200, 302, 303].includes(result.cleanup_status)
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})();
