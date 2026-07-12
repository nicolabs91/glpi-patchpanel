const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

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

  const response = await page.goto(`${baseUrl}/plugins/patchpanel/front/health.php`, {
    waitUntil: 'networkidle',
  });
  const body = await page.locator('body').innerText();
  const rows = await page.locator('.patchpanel-health tbody tr').evaluateAll(items =>
    items.map(item => item.textContent.replace(/\s+/g, ' ').trim())
  );
  await page.screenshot({
    path: 'artifacts/patchpanel-v01-health-check.png',
    fullPage: true,
  });

  const result = {
    status: response.status(),
    healthy: body.includes('PatchPanel data is healthy.'),
    headings: {
      main: body.includes('PatchPanel health check'),
      indexes: body.includes('Performance indexes'),
      integrity: body.includes('Data integrity'),
    },
    row_count: rows.length,
    missing: rows.filter(row => row.includes('Missing') && row.includes('Needs attention')),
    needs_attention: rows.filter(row => row.includes('Needs attention')),
    browser_errors: browserErrors,
  };

  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    result.status !== 200
    || !result.healthy
    || !Object.values(result.headings).every(Boolean)
    || result.row_count < 19
    || result.missing.length
    || result.needs_attention.length
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
