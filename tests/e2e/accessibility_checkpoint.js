const AxeBuilder = require('@axe-core/playwright').default;
const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

async function scan(page, selectors) {
  let builder = new AxeBuilder({ page }).withTags([
    'wcag2a',
    'wcag2aa',
    'wcag21a',
    'wcag21aa',
  ]);
  for (const selector of selectors) {
    builder = builder.include(selector);
  }
  const result = await builder.analyze();
  return result.violations.map(violation => ({
    id: violation.id,
    impact: violation.impact,
    nodes: violation.nodes.length,
  }));
}

(async () => {
  const browser = await launchBrowser();
  const context = await browser.newContext();
  const page = await context.newPage({ viewport: { width: 1600, height: 1100 } });
  let panelId = 0;

  try {
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    await page.fill('input[name="login_name"]', username);
    await page.fill('input[name="login_password"]', password);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle');

    await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.php`, {
      waitUntil: 'networkidle',
    });
    const listActions = await scan(page, ['.patchpanel-list-actions']);
    const addButton = page.locator(
      'a[href="/plugins/patchpanel/front/panel.form.php?id=-1"]',
      { hasText: 'Add patch panel' },
    ).first();
    if (!await addButton.isVisible()) {
      throw new Error('Add patch panel is not visible on the empty list page');
    }
    await addButton.click();
    await page.waitForURL(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`);
    await page.waitForLoadState('networkidle');
    const panelName = `PP-A11Y-${Date.now()}`;
    await page.fill('input[name="name"]', panelName);
    await page.fill('input[name="port_count"]', '4');
    await page.locator('button[name="add"], input[name="add"]').click();
    await page.waitForLoadState('networkidle');
    panelId = Number(new URL(page.url()).searchParams.get('id'));

    await page.locator('a, button').filter({ hasText: /Visual panel/i }).first().click();
    await page.locator('.patchpanel-port').first().waitFor();
    const visual = await scan(page, [
      '.patchpanel-legend',
      '.patchpanel-grid',
    ]);
    const portHref = await page.locator('.patchpanel-port').first().getAttribute('href');

    await page.goto(new URL(portHref, baseUrl).toString(), { waitUntil: 'networkidle' });
    await page.fill('input[name="label"]', 'Accessibility audit');
    await page.locator('button[name="update"], input[name="update"]').click();
    await page.waitForLoadState('networkidle');
    const route = await scan(page, ['.patchpanel-route-section']);

    await page.goto(
      `${baseUrl}/plugins/patchpanel/front/health.php`,
      { waitUntil: 'networkidle' },
    );
    const health = await scan(page, ['.patchpanel-health']);

    await page.goto(
      `${baseUrl}/plugins/patchpanel/front/labels.php?panel_id=${panelId}`,
      { waitUntil: 'networkidle' },
    );
    const labels = await scan(page, [
      '.patchpanel-label-controls',
      '.patchpanel-label-sheet',
    ]);

    await page.goto(
      `${baseUrl}/plugins/patchpanel/front/audit.php?panel_id=${panelId}`,
      { waitUntil: 'networkidle' },
    );
    const audit = await scan(page, ['section.card']);

    const result = { listActions, visual, route, health, labels, audit };
    console.log(JSON.stringify(result, null, 2));
    if (Object.values(result).some(violations => violations.length > 0)) {
      process.exitCode = 1;
    }
  } finally {
    if (panelId > 0) {
      await page.goto(
        `${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${panelId}`,
        { waitUntil: 'networkidle' },
      );
      const token = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
      await page.request.post(
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
    }
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
