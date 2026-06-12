const { launchBrowser } = require('./helpers');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

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

  await page.goto(`${baseUrl}/front/socket.form.php?id=86`, {
    waitUntil: 'networkidle',
  });
  const socketSelection = {
    itemtype: await page.locator('select[name="itemtype"]').inputValue(),
    item: await page.locator('select[name="items_id"]').inputValue(),
    networkport: await page.locator('select[name="networkports_id"]').inputValue(),
  };

  await page.goto(
    `${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=2605`,
    { waitUntil: 'networkidle' },
  );
  const routeSteps = await page.locator('.patchpanel-route-step').evaluateAll(steps =>
    steps.map(step => ({
      text: step.textContent.trim(),
      href: step.getAttribute('href'),
    }))
  );

  const result = {
    socket_selection: socketSelection,
    route_start: routeSteps.slice(0, 3),
    terminal_matches_socket:
      routeSteps[0]?.text === 'TV 001'
      && routeSteps[0]?.href?.includes('id=174')
      && routeSteps[1]?.text === 'LAN'
      && routeSteps[1]?.href?.includes('id=73')
      && routeSteps[2]?.text === 'Room 0101 TV outlet'
      && routeSteps[2]?.href?.includes('id=86'),
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));

  await browser.close();

  if (
    socketSelection.itemtype !== 'NetworkEquipment'
    || socketSelection.item !== '174'
    || socketSelection.networkport !== '73'
    || !result.terminal_matches_socket
    || errors.length
  ) {
    process.exitCode = 1;
  }
})();
