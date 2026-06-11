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

  await page.goto(`${baseUrl}/plugins/patchpanel/front/routes.php`, {
    waitUntil: 'networkidle',
  });
  const emptyBody = await page.locator('body').innerText();
  const emptyResultCount = await page.locator('.patchpanel-explorer-result').count();

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`, {
    waitUntil: 'networkidle',
  });
  const panelName = `PP-ROUTE-EXPLORER-${Date.now()}`;
  await page.fill('input[name="name"]', panelName);
  await page.fill('input[name="port_count"]', '2');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');
  const panelId = Number(new URL(page.url()).searchParams.get('id'));

  await page.locator('a, button').filter({ hasText: /Visual panel/i }).first().click();
  const firstPortHref = await page.locator('.patchpanel-port').first().getAttribute('href');
  await page.goto(new URL(firstPortHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  await selectValue(page, 'rear_items_id', 88, 'Kamer 0102 Wall outlet');
  await selectValue(page, 'front_items_id', 226, 'SW-L1-IDF-A - Port 02');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');

  const query = encodeURIComponent(`${panelName} FW-L1-MDF-01`);
  const response = await page.goto(
    `${baseUrl}/plugins/patchpanel/front/routes.php?q=${query}`,
    { waitUntil: 'networkidle' },
  );
  const searchBody = await page.locator('body').innerText();
  const results = page.locator('.patchpanel-explorer-result');
  const routeSteps = page.locator('.patchpanel-explorer-result .patchpanel-route-step');
  const firewallImpact = page.locator('.patchpanel-impact-links a', {
    hasText: 'FW-L1-MDF-01',
  }).first();
  const impactHref = await firewallImpact.getAttribute('href');
  const searchResultCount = await results.count();
  const routeStepCount = await routeSteps.count();

  await page.screenshot({
    path: 'artifacts/patchpanel-v2-route-explorer.png',
    fullPage: true,
  });

  await page.goto(new URL(impactHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  const impactBody = await page.locator('body').innerText();
  const impactResults = await page.locator('.patchpanel-explorer-result').count();

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
    empty_prompt: emptyBody.includes('Enter one or more terms to search physical routes.'),
    empty_results: emptyResultCount,
    search_results: searchResultCount,
    search_has_panel: searchBody.includes(panelName),
    search_has_outlet: searchBody.includes('Kamer 0102 Wall outlet'),
    search_has_access_switch: searchBody.includes('SW-L1-IDF-A'),
    search_has_core: searchBody.includes('SW-L1-MDF-CORE-01'),
    search_has_firewall: searchBody.includes('FW-L1-MDF-01'),
    clickable_steps: routeStepCount,
    impact_filter_visible:
      impactBody.includes('patch panel routes depend on FW-L1-MDF-01'),
    impact_results: impactResults,
    impact_has_panel: impactBody.includes(panelName),
    cleanup_status: cleanup.status(),
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    result.status !== 200
    || !result.empty_prompt
    || result.empty_results !== 0
    || result.search_results !== 1
    || !result.search_has_panel
    || !result.search_has_outlet
    || !result.search_has_access_switch
    || !result.search_has_core
    || !result.search_has_firewall
    || result.clickable_steps < 7
    || !result.impact_filter_visible
    || result.impact_results < 1
    || !result.impact_has_panel
    || ![200, 302, 303].includes(result.cleanup_status)
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})();
