const { chromium } = require('playwright');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
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

  const migrationUrl = `${baseUrl}/plugins/patchpanel/front/migration.php`;
  const previewResponse = await page.goto(migrationUrl, { waitUntil: 'networkidle' });
  const previewBody = await page.locator('body').innerText();
  assert(previewResponse.status() === 200, 'Migration preview did not return HTTP 200');
  assert(previewBody.includes('Legacy panels\n4'), 'Legacy panel count is missing');
  assert(previewBody.includes('Legacy ports\n72'), 'Legacy port count is missing');
  assert(previewBody.includes('Ready ports\n52'), 'Ready port count is unexpected');
  assert(previewBody.includes('Empty ports\n15'), 'Empty port count is unexpected');
  assert(previewBody.includes('Partial ports\n1'), 'Partial port count is unexpected');
  assert(previewBody.includes('Conflicting ports\n4'), 'Conflict port count is unexpected');

  const panelCheckboxes = page.locator('input[name="legacy_panels[]"]');
  for (let index = 0; index < await panelCheckboxes.count(); index += 1) {
    await panelCheckboxes.nth(index).uncheck();
  }
  await page.locator('input[name="legacy_panels[]"][value="2"]').check();
  await page.locator('input[name="confirm_import"]').check();
  await page.locator('button[name="import_selected"]').click();
  await page.waitForLoadState('networkidle');

  const importedBody = await page.locator('body').innerText();
  assert(
    importedBody.includes('Imported 1 panels and 24 ports. 0 ports are partial and 2 contain conflicts.'),
    'Import result message is missing or incorrect',
  );
  const panelLink = page.locator('a').filter({ hasText: /New panel #/ }).first();
  const panelHref = await panelLink.getAttribute('href');
  assert(panelHref, 'Imported panel link is missing');
  assert(
    await page.locator('input[name="legacy_panels[]"][value="2"]').count() === 0,
    'Imported panel was not marked as imported',
  );
  const panelId = Number(new URL(panelHref, baseUrl).searchParams.get('id'));
  assert(panelId > 0, 'Imported panel id is invalid');

  await page.goto(new URL(panelHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  await page.locator('a, button').filter({ hasText: /Visual panel/i }).first().click();
  await page.locator('.patchpanel-port').first().waitFor({ state: 'visible' });
  assert(await page.locator('.patchpanel-port').count() === 24, 'Imported panel does not have 24 visual ports');

  const firstPortHref = await page.locator('.patchpanel-port').first().getAttribute('href');
  assert(firstPortHref, 'First imported port is not clickable');
  await page.goto(new URL(firstPortHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  const routeBody = await page.locator('body').innerText();
  assert(routeBody.includes('PP-L1-IDF-A'), 'Imported patch panel is missing from the route');
  assert(routeBody.includes('SW-L1-IDF-A'), 'Access switch is missing from the route');
  assert(
    routeBody.includes('SW-L1-MDF-CORE-01') && routeBody.includes('Core switch'),
    'Core switch is missing from the route',
  );
  assert(
    routeBody.includes('RTR-FW-01') || routeBody.includes('Firewall'),
    'Router or firewall is missing from the route',
  );
  assert(
    await page.locator('.patchpanel-route a').count() >= 4,
    'The imported physical route is not fully clickable',
  );

  await page.goto(migrationUrl, { waitUntil: 'networkidle' });
  const duplicateCheckbox = page.locator('input[name="legacy_panels[]"][value="2"]');
  assert(await duplicateCheckbox.count() === 0, 'Already imported panel can still be selected');

  const rollbackForm = page.locator('form').filter({ has: page.locator('button[name="rollback_batch"]') }).first();
  const batch = await rollbackForm.locator('input[name="batch_uuid"]').inputValue();
  assert(/^[a-f0-9]{32}$/.test(batch), 'Migration batch identifier is invalid');
  await rollbackForm.locator('button[name="rollback_batch"]').evaluate(button => {
    button.removeAttribute('onclick');
  });
  await rollbackForm.locator('button[name="rollback_batch"]').click();
  await page.waitForLoadState('networkidle');

  const rollbackBody = await page.locator('body').innerText();
  assert(
    rollbackBody.includes('Rolled back 1 imported panels. Legacy data was not changed.'),
    'Rollback result message is missing',
  );
  assert(rollbackBody.includes('No active migration batch exists.'), 'Migration batch remains active');
  assert(
    await page.locator('input[name="legacy_panels[]"][value="2"]').count() === 1,
    'Rolled-back panel is not available for a new preview',
  );

  const result = {
    preview_status: previewResponse.status(),
    imported_panel_id: panelId,
    visual_ports: 24,
    clickable_route: true,
    route_has_access_switch: true,
    route_has_core: true,
    route_has_router_or_firewall: true,
    duplicate_import_blocked: true,
    rollback_batch: batch,
    rollback_complete: true,
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));

  assert(errors.length === 0, `Browser errors detected: ${errors.join('; ')}`);
  await browser.close();
})().catch(error => {
  console.error(error);
  process.exit(1);
});
