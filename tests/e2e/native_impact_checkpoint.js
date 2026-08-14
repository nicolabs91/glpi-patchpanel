const { execFileSync } = require('child_process');
const { launchBrowser } = require('./helpers');
const { cleanupRouteFixture, createRouteFixture } = require('./fixtures');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

function synchronizeImpact() {
  const code = String.raw`
chdir('/var/www/glpi');
require 'vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
if (!PluginPatchpanelImpact::synchronize()) {
    throw new RuntimeException('PatchPanel impact synchronization failed.');
}
`;
  execFileSync('docker', ['exec', 'glpi-app', 'php', '-r', code]);
}

(async () => {
  const fixture = createRouteFixture('NATIVE-IMPACT', { withUpstream: true });
  synchronizeImpact();
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1700, height: 1200 } });
  const errors = [];
  page.on('pageerror', error => errors.push(`pageerror: ${error.message}`));
  page.on('response', async response => {
    if (response.status() >= 400) {
      let detail = '';
      try { detail = (await response.text()).slice(0, 2000); } catch {}
      errors.push(`${response.status()} ${response.url()} ${detail}`);
    }
  });

  try {
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    await page.fill('input[name="login_name"]', username);
    await page.fill('input[name="login_password"]', password);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle');

    await page.goto(`${baseUrl}/front/networkequipment.form.php?id=${fixture.firewallId}`, {
      waitUntil: 'networkidle',
    });
    const impactTab = page.getByRole('tab', { name: /Impact analysis/ });
    const tabLabel = (await impactTab.innerText()).trim();
    await page.goto(
      `${baseUrl}/front/networkequipment.form.php?id=${fixture.firewallId}&forcetab=Impact%241`,
      { waitUntil: 'networkidle' },
    );
    if (errors.length) throw new Error(errors.join('\n'));
    await page.locator('[data-testid="impact-graph-view"]').waitFor({ state: 'visible' });
    await page.waitForFunction(() => window.GLPIImpact?.cy?.nodes().length > 1);
    const graph = await page.evaluate(() => ({
      nodes: window.GLPIImpact.cy.nodes().length,
      edges: window.GLPIImpact.cy.edges().length,
      labels: window.GLPIImpact.cy.nodes().map(node => JSON.stringify(node.data())).join(' '),
    }));
    await page.screenshot({
      path: 'artifacts/patchpanel-native-impact-sol.png',
      fullPage: true,
    });

    const result = {
      tab_label: tabLabel,
      graph_nodes: graph.nodes,
      graph_edges: graph.edges,
      has_firewall: graph.labels.includes(fixture.names.firewall),
      has_core: graph.labels.includes(fixture.names.coreSwitch),
      has_access: graph.labels.includes(fixture.names.accessSwitch),
      has_endpoint: graph.labels.includes(fixture.names.endpoint),
      browser_errors: errors,
    };
    console.log(JSON.stringify(result, null, 2));
    if (
      graph.nodes < 4
      || graph.edges < 3
      || !result.has_firewall
      || !result.has_core
      || !result.has_access
      || !result.has_endpoint
      || errors.length
    ) {
      process.exitCode = 1;
    }
  } finally {
    await browser.close();
    cleanupRouteFixture(fixture);
    synchronizeImpact();
  }
})();
