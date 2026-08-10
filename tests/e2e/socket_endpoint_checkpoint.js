const { launchBrowser } = require('./helpers');
const {
  cleanupRouteFixture,
  createRouteFixture,
  queryDb,
} = require('./fixtures');

const baseUrl = process.env.GLPI_URL || 'http://127.0.0.1:8088';
const username = process.env.GLPI_USER || 'glpi';
const password = process.env.GLPI_PASSWORD || 'glpi';

(async () => {
  const fixture = createRouteFixture('SOCKET-ENDPOINT');
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport: { width: 1600, height: 1100 } });
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

    await page.goto(`${baseUrl}/front/socket.form.php?id=${fixture.socketId}`, {
      waitUntil: 'networkidle',
    });
    const socketSelection = {
      itemtype: await page.locator('select[name="itemtype"]').inputValue(),
      item: await page.locator('select[name="items_id"]').inputValue(),
      networkport: await page.locator('select[name="networkports_id"]').inputValue(),
    };
    const selectedSocketItemLabel = await page
      .locator('select[name="items_id"] option:checked')
      .innerText();
    const selectedSocketPortLabel = await page
      .locator('select[name="networkports_id"] option:checked')
      .innerText();

    await page.goto(
      `${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${fixture.panelPortId}`,
      { waitUntil: 'networkidle' },
    );
    const connectedPortFormText = await page.locator('body').innerText();
    const connectedRouteFullText = await page.locator('.patchpanel-route').evaluate(element =>
      element.textContent.replace(/\s+/g, ' ').trim()
    );
    const routeSteps = await page.locator('.patchpanel-route-step').evaluateAll(steps =>
      steps.map(step => ({
        text: step.textContent.trim(),
        zone: step.getAttribute('data-route-zone'),
        href: step.getAttribute('href'),
      }))
    );

    const nativeFrontPortId = queryDb(
      `SELECT items_id FROM glpi_plugin_patchpanel_portendpoints WHERE plugin_patchpanel_panelports_id = ${fixture.panelPortId} AND side = 'front' LIMIT 1`
    );
    await page.goto(`${baseUrl}/front/networkport.form.php?id=${nativeFrontPortId}`, {
      waitUntil: 'networkidle',
    });
    const nativeConnectedPanelPortLinks = await page
      .locator(`a[href*="/plugins/patchpanel/front/panelport.form.php?id=${fixture.panelPortId}"]`)
      .count();
    const nativeConnectedShadowPortLinks = await page
      .locator('a[href*="/front/networkport.form.php?id="]')
      .filter({ hasText: fixture.names.panel })
      .count();
    await page.goto(`${baseUrl}/front/networkport.form.php?id=${socketSelection.networkport}`, {
      waitUntil: 'networkidle',
    });
    await page.locator('a, button').filter({ hasText: /Patch panel routes/i }).first().click();
    await page.waitForTimeout(1000);
    const networkPortTabText = await page.locator('body').innerText();
    const networkPortRouteCards = await page.locator('.patchpanel-endpoint-route').count();

    queryDb(
      `UPDATE glpi_sockets SET itemtype = 'NetworkEquipment', items_id = ${fixture.endpointId}, networkports_id = 0 WHERE id = ${fixture.socketId}`
    );
    await page.goto(
      `${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${fixture.panelPortId}`,
      { waitUntil: 'networkidle' },
    );
    const disconnectedRouteText = await page.locator('.patchpanel-route').innerText();
    const disconnectedPortFormText = await page.locator('body').innerText();

    await page.goto(`${baseUrl}/front/socket.form.php?id=${fixture.socketId}`, {
      waitUntil: 'networkidle',
    });
    await page.locator('a, button').filter({ hasText: /Patch panel routes/i }).first().click();
    await page.waitForTimeout(1000);
    const disconnectedSocketTabText = await page.locator('body').innerText();
    const staleSocketClearActions = await page
      .locator('button[name="disconnect_socket_device"]')
      .count();

    await page.goto(`${baseUrl}/front/socket.form.php?id=${fixture.socketId}`, {
      waitUntil: 'networkidle',
    });
    const socketMainTab = page.locator('a, button').filter({ hasText: /^Socket$/ }).first();
    if (await socketMainTab.count()) {
      await socketMainTab.click();
      await page.waitForLoadState('networkidle');
    }
    await page.locator('button[name="update"], input[name="update"], button:has-text("Save")').first().click();
    await page.waitForLoadState('networkidle');
    const cleanedSocketSelection = queryDb(
      `SELECT CONCAT(COALESCE(itemtype, 'NULL'), '|', items_id, '|', networkports_id) FROM glpi_sockets WHERE id = ${fixture.socketId}`
    );

    await page.goto(`${baseUrl}/front/networkequipment.form.php?id=${fixture.endpointId}`, {
      waitUntil: 'networkidle',
    });
    await page.locator('a, button').filter({ hasText: /Patch panel routes/i }).first().click();
    await page.waitForTimeout(1000);
    const disconnectedDeviceTabText = await page.locator('body').innerText();

    const result = {
      socket_selection: socketSelection,
      socket_labels: {
        item: selectedSocketItemLabel.trim(),
        networkport: selectedSocketPortLabel.trim(),
      },
      route_start: routeSteps.slice(0, 3),
      terminal_matches_socket:
        routeSteps[0]?.text === `${selectedSocketItemLabel.trim()} · ${selectedSocketPortLabel.trim()}`
        && routeSteps[0]?.href?.includes(`/front/networkequipment.form.php?id=${socketSelection.item}`)
        && routeSteps[1]?.text.includes(fixture.names.socket)
        && routeSteps[1]?.href?.includes(`id=${fixture.socketId}`),
      port_form_uses_physical_route_for_terminal:
        !connectedPortFormText.includes('End device on endpoint')
        && !connectedPortFormText.includes('Disconnect end device from endpoint')
        && connectedPortFormText.includes('Physical route')
        && connectedPortFormText.includes(fixture.names.endpoint),
      connected_route_stops_at_access_switch:
        connectedRouteFullText.includes(fixture.names.accessSwitch)
        && connectedRouteFullText.includes(fixture.names.panel)
        && routeSteps[4]?.text.includes(fixture.names.accessSwitch)
        && routeSteps[4]?.zone === 'access'
        && !routeSteps.slice(5).some(step =>
          step.text.includes(fixture.names.endpoint)
          || step.href?.includes(`/front/networkequipment.form.php?id=${socketSelection.item}`)
        ),
      networkport_tab_matches_socket_route:
        networkPortRouteCards >= 1
        && networkPortTabText.includes(fixture.names.panel)
        && networkPortTabText.includes('Rear side: permanent cabling')
        && networkPortTabText.includes(fixture.names.endpoint)
        && networkPortTabText.includes(fixture.names.socket)
        && networkPortTabText.includes('Connection details'),
      native_connected_to_hides_shadow_port:
        nativeConnectedPanelPortLinks === 1 && nativeConnectedShadowPortLinks === 0,
      disconnected_socket_ignored:
        !disconnectedRouteText.includes(fixture.names.endpoint)
        && !disconnectedRouteText.includes(fixture.names.endpointPort)
        && disconnectedRouteText.includes(fixture.names.socket),
      disconnected_port_form_ignores_stale_socket_device:
        !disconnectedPortFormText.includes('GLPI device selected only; no LAN port connected.')
        && !disconnectedPortFormText.includes('Clean up GLPI device selection')
        && disconnectedPortFormText.includes('Physical route')
        && disconnectedPortFormText.includes(fixture.names.socket)
        && !disconnectedRouteText.includes(fixture.names.endpoint),
      disconnected_socket_tab_warning:
        disconnectedSocketTabText.includes('GLPI device selected only; no LAN port connected.')
        && disconnectedSocketTabText.includes('Clean up GLPI device selection')
        && staleSocketClearActions === 1,
      native_socket_save_clears_stale_device: cleanedSocketSelection === 'NULL|0|0',
      disconnected_device_tab_clear:
        disconnectedDeviceTabText.includes('This object is not directly registered on a patch panel route.'),
      browser_errors: errors,
    };
    console.log(JSON.stringify(result, null, 2));

    if (
      socketSelection.itemtype !== 'NetworkEquipment'
      || !socketSelection.item
      || !socketSelection.networkport
      || !result.terminal_matches_socket
      || !result.port_form_uses_physical_route_for_terminal
      || !result.connected_route_stops_at_access_switch
      || !result.networkport_tab_matches_socket_route
      || !result.native_connected_to_hides_shadow_port
      || !result.disconnected_socket_ignored
      || !result.disconnected_port_form_ignores_stale_socket_device
      || !result.disconnected_socket_tab_warning
      || !result.native_socket_save_clears_stale_device
      || !result.disconnected_device_tab_clear
      || errors.length
    ) {
      process.exitCode = 1;
    }
  } finally {
    await browser.close();
    cleanupRouteFixture(fixture);
  }
})();
