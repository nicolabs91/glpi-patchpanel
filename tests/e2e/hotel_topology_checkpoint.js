const { launchBrowser } = require('./helpers');
const { queryDb } = require('./fixtures');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

async function login(page) {
  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.fill('input[name="login_name"]', username);
  await page.fill('input[name="login_password"]', password);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');
}

async function openTab(page, pattern) {
  const tab = page.locator('a, button').filter({ hasText: pattern }).first();
  if (await tab.count() !== 1) {
    throw new Error(`Tab ${pattern} was not found.`);
  }
  await tab.click();
  await page.waitForTimeout(900);
}

(async () => {
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1700, height: 1200 } });
  const browserErrors = [];
  page.on('pageerror', error => browserErrors.push(`pageerror: ${error.message}`));
  page.on('console', message => {
    if (message.type() === 'error') browserErrors.push(`console: ${message.text()}`);
  });
  page.on('response', response => {
    if (response.status() >= 500) browserErrors.push(`${response.status()} ${response.url()}`);
  });

  try {
    await login(page);

    await page.goto(`${baseUrl}/front/networkequipment.form.php?id=1`, { waitUntil: 'networkidle' });
    await openTab(page, /Network ports/i);
    const apHasPort = (await page.locator('body').innerText()).includes('LAN');

    await openTab(page, /Patch panel routes/i);
    const apRouteText = await page.locator('body').innerText();
    const apRouteComplete = apRouteText.includes('HTL-PP-L1-01')
      && apRouteText.includes('HTL-SW-L1-01');
    const apRouteUsesOwnerAndPort = apRouteText.includes('HTL-AP-L1-01 · LAN');
    const apRouteHasNoGenericEth0 = !apRouteText.toLowerCase().includes('eth0');

    await page.goto(`${baseUrl}/front/networkequipment.form.php?id=21`, { waitUntil: 'networkidle' });
    await openTab(page, /Network ports/i);
    const switchText = await page.locator('body').innerText();
    const switchHasAccessAndUplinks = switchText.includes('Gi1/0/1')
      && switchText.includes('Te1/1/4');

    const backbonePortId = Number(queryDb(
      `SELECT np.id
       FROM glpi_networkports np
       INNER JOIN glpi_networkequipments ne
         ON ne.id = np.items_id AND np.itemtype = 'NetworkEquipment'
       WHERE ne.name = 'HTL-SW-L2-02'
         AND np.logical_number = 49
         AND np.is_deleted = 0
       LIMIT 1`
    ));
    await page.goto(`${baseUrl}/front/networkport.form.php?id=${backbonePortId}`, {
      waitUntil: 'networkidle',
    });
    await openTab(page, /Patch panel routes/i);
    const backboneText = await page.locator('body').innerText();
    const backboneSymmetric = backboneText.includes('HTL-PP-L1-01')
      && backboneText.includes('HTL-PP-L2-01')
      && backboneText.includes('HTL-BACKBONE-L1-L2');

    const result = {
      ap_has_logical_port: apHasPort,
      ap_route_complete: apRouteComplete,
      ap_route_uses_owner_and_port: apRouteUsesOwnerAndPort,
      ap_route_has_no_generic_eth0: apRouteHasNoGenericEth0,
      switch_has_52_port_range: switchHasAccessAndUplinks,
      backbone_symmetric: backboneSymmetric,
      browser_errors: browserErrors,
    };
    console.log(JSON.stringify(result, null, 2));
    if (!Object.values(result).slice(0, 6).every(Boolean) || browserErrors.length) {
      process.exitCode = 1;
    }
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
