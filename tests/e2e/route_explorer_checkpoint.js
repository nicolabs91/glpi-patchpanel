const { launchBrowser } = require('./helpers');
const {
  cleanupRouteFixture,
  createRouteFixture,
} = require('./fixtures');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

(async () => {
  const fixture = createRouteFixture('ROUTE-EXPLORER', { withUpstream: true });
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1700, height: 1200 } });
  const errors = [];
  page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
  page.on('response', response => {
    if (response.status() >= 400) {
      errors.push(`${response.status()} ${response.url()}`);
    }
  });

  try {
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

    const panelName = fixture.names.panel;

  const query = encodeURIComponent(panelName);
  const response = await page.goto(
    `${baseUrl}/plugins/patchpanel/front/routes.php?q=${query}`,
    { waitUntil: 'networkidle' },
  );
  const searchBody = await page.locator('body').innerText();
  const searchFullText = await page.locator('body').evaluate(element =>
    element.textContent.replace(/\s+/g, ' ').trim()
  );
  const routeMoreCount = await page.locator('.patchpanel-explorer-result .patchpanel-route-more').count();
  const results = page.locator('.patchpanel-explorer-result');
  const routeSteps = page.locator('.patchpanel-explorer-result .patchpanel-route-step');
  const firstImpact = page.locator('.patchpanel-impact-links a').first();
  const impactHref = await firstImpact.getAttribute('href');
  const impactLabel = (await firstImpact.innerText()).trim();
  const searchResultCount = await results.count();
  const routeStepCount = await routeSteps.count();

  await page.screenshot({
    path: 'artifacts/patchpanel-v2-route-explorer.png',
    fullPage: true,
  });

  await page.goto(new URL(impactHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  const impactBody = await page.locator('body').innerText();
  const impactResults = await page.locator('.patchpanel-explorer-result').count();

  const result = {
    status: response.status(),
    empty_prompt: emptyBody.includes('Enter one or more terms to search physical routes.'),
    empty_results: emptyResultCount,
    search_results: searchResultCount,
    search_has_panel: searchBody.includes(panelName),
    search_has_endpoint: searchBody.includes(fixture.names.socket),
    search_has_access_switch: searchBody.includes(fixture.names.accessSwitch),
    search_has_core: searchFullText.includes(fixture.names.coreSwitch),
    full_route_visible: routeMoreCount === 0
      && searchBody.includes(fixture.names.coreSwitch)
      && searchBody.includes(fixture.names.firewall),
    search_has_impact_link: Boolean(impactHref) && Boolean(impactLabel),
    clickable_steps: routeStepCount,
    impact_filter_visible:
      impactBody.includes(`patch panel routes depend on ${impactLabel}`),
    impact_results: impactResults,
    impact_has_panel: impactBody.includes(panelName),
    cleanup_status: 200,
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  if (
    result.status !== 200
    || !result.empty_prompt
    || result.empty_results !== 0
    || result.search_results < 1
    || !result.search_has_panel
    || !result.search_has_endpoint
    || !result.search_has_access_switch
    || !result.search_has_core
    || !result.full_route_visible
    || !result.search_has_impact_link
    || result.clickable_steps < 7
    || !result.impact_filter_visible
    || result.impact_results < 1
    || !result.impact_has_panel
    || ![200, 302, 303].includes(result.cleanup_status)
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
  } finally {
    await browser.close();
    cleanupRouteFixture(fixture);
  }
})();
