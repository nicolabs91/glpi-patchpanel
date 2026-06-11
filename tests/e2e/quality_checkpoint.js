const { chromium } = require('playwright');

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
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  });
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

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`, {
    waitUntil: 'networkidle',
  });
  const panelName = `PP-QUALITY-${Date.now()}`;
  await page.fill('input[name="name"]', panelName);
  await page.fill('input[name="port_count"]', '4');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');
  const panelId = Number(new URL(page.url()).searchParams.get('id'));

  await page.locator('a, button').filter({ hasText: /Visual panel/i }).first().click();
  await page.locator('.patchpanel-port').first().waitFor({ state: 'visible' });
  const firstPortHref = await page.locator('.patchpanel-port').first().getAttribute('href');
  await page.goto(new URL(firstPortHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  await selectValue(page, 'rear_items_id', 88, 'Kamer 0102 Wall outlet');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');

  const response = await page.goto(
    `${baseUrl}/plugins/patchpanel/front/quality.php?q=${encodeURIComponent(panelName)}`,
    { waitUntil: 'networkidle' },
  );
  const body = await page.locator('body').innerText();
  const rows = page.locator('tbody tr');
  const rowCount = await rows.count();
  const partialRows = rows.filter({ hasText: 'Incomplete' });
  const freeRows = rows.filter({ hasText: 'Free' });
  const routeLinks = page.locator('a').filter({ hasText: 'Open route' });
  const incompleteRowCount = await partialRows.count();
  const freeRowCount = await freeRows.count();
  const routeLinkCount = await routeLinks.count();

  await page.screenshot({
    path: 'artifacts/patchpanel-v2-quality-dashboard.png',
    fullPage: true,
  });

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
    status: response.status(),
    heading: body.includes('Cabling quality'),
    panel_visible: body.includes(panelName),
    rows: rowCount,
    incomplete_rows: incompleteRowCount,
    free_rows: freeRowCount,
    route_links: routeLinkCount,
    cleanup_status: cleanup.status(),
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    result.status !== 200
    || !result.heading
    || !result.panel_visible
    || result.rows !== 4
    || result.incomplete_rows !== 1
    || result.free_rows !== 3
    || result.route_links !== 4
    || ![200, 302, 303].includes(result.cleanup_status)
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})();
