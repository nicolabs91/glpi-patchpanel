const { execFileSync } = require('child_process');
const { launchBrowser } = require('./helpers');

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

function queryDb(sql) {
  return execFileSync('docker', [
    'exec',
    'glpi-db',
    'mariadb',
    '-uglpi',
    '-pQ7f2mK9xT8pL4vN6dR1sW3yZ',
    'glpi',
    '-N',
    '-e',
    sql,
  ], { encoding: 'utf8' }).trim();
}

function resetAp001Route() {
  queryDb(
    "UPDATE glpi_sockets SET itemtype = 'NetworkEquipment', items_id = 1, networkports_id = 217 WHERE id = 86"
  );
  queryDb(
    "DELETE FROM glpi_plugin_patchpanel_portendpoints WHERE itemtype = 'Glpi\\\\Socket' AND items_id = 86 AND plugin_patchpanel_panelports_id <> 2605"
  );
  queryDb(
    "DELETE FROM glpi_plugin_patchpanel_portendpoints WHERE plugin_patchpanel_panelports_id = 2605 AND side = 'rear'"
  );
  queryDb(
    "INSERT INTO glpi_plugin_patchpanel_portendpoints (plugin_patchpanel_panelports_id, side, itemtype, items_id, cables_id, cable_color, cable_label, date_mod, date_creation) VALUES (2605, 'rear', 'Glpi\\\\Socket', 86, 0, NULL, NULL, NOW(), NOW())"
  );
}

async function openTabByText(page, text) {
  await page.locator('a, button').filter({ hasText: text }).first().click();
  await page.waitForTimeout(1000);
}

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
  resetAp001Route();

  let ap001DeviceDisconnect = null;
  let ap001RearDisconnect = null;
  try {
    await page.goto(`${baseUrl}/front/networkequipment.form.php?id=1`, {
      waitUntil: 'networkidle',
    });
    await openTabByText(page, /Patch panel routes/i);
    const ap001Card = page.locator('.patchpanel-endpoint-route')
      .filter({ hasText: 'PP-L1-IDF-A' })
      .first();
    await ap001Card.waitFor({ state: 'visible' });
    const beforeText = await ap001Card.innerText();
    const actionCount = await ap001Card
      .locator('button[name="disconnect_socket_device"]')
      .count();
    await ap001Card.locator('button[name="disconnect_socket_device"]').click();
    await page.waitForLoadState('networkidle');
    const after = queryDb(
      "SELECT CONCAT(COALESCE(itemtype, ''), '|', items_id, '|', networkports_id) FROM glpi_sockets WHERE id = 86"
    ).split('|');
    ap001DeviceDisconnect = {
      route_visible: beforeText.includes('NLH-R0101-TV01') && beforeText.includes('NLH-R0101-WA-TV01'),
      connection_details:
        beforeText.includes('Connection details')
        && beforeText.includes('Rear permanent link')
        && beforeText.includes('Front patch link')
        && beforeText.includes('Route health'),
      action_count: actionCount,
      itemtype: after[0] ?? '',
      items_id: after[1] ?? '',
      networkports_id: after[2] ?? '',
    };
  } finally {
    queryDb(
      "UPDATE glpi_sockets SET itemtype = 'NetworkEquipment', items_id = 1, networkports_id = 217 WHERE id = 86"
    );
  }

  try {
    queryDb(
      "UPDATE glpi_sockets SET itemtype = 'NetworkEquipment', items_id = 1, networkports_id = 217 WHERE id = 86"
    );
    queryDb(
      "DELETE FROM glpi_plugin_patchpanel_portendpoints WHERE plugin_patchpanel_panelports_id = 2605 AND side = 'rear'"
    );
    queryDb(
      "INSERT INTO glpi_plugin_patchpanel_portendpoints (plugin_patchpanel_panelports_id, side, itemtype, items_id, cables_id, cable_color, cable_label, date_mod, date_creation) VALUES (2605, 'rear', 'Glpi\\\\Socket', 86, 0, NULL, NULL, NOW(), NOW())"
    );

    await page.goto(`${baseUrl}/front/socket.form.php?id=86`, {
      waitUntil: 'networkidle',
    });
    await openTabByText(page, /Patch panel routes/i);
    const ap001RearCard = page.locator('.patchpanel-endpoint-route')
      .filter({ hasText: 'PP-L1-IDF-A' })
      .first();
    await ap001RearCard.waitFor({ state: 'visible' });
    await ap001RearCard.locator('button[name="disconnect_endpoint"]').click();
    await page.waitForLoadState('networkidle');
    const afterSocket = queryDb(
      "SELECT CONCAT(COALESCE(itemtype, ''), '|', items_id, '|', networkports_id) FROM glpi_sockets WHERE id = 86"
    ).split('|');
    const rearEndpointCount = queryDb(
      "SELECT COUNT(*) FROM glpi_plugin_patchpanel_portendpoints WHERE plugin_patchpanel_panelports_id = 2605 AND side = 'rear'"
    );
    ap001RearDisconnect = {
      rear_endpoint_count: rearEndpointCount,
      itemtype: afterSocket[0] ?? '',
      items_id: afterSocket[1] ?? '',
      networkports_id: afterSocket[2] ?? '',
    };
  } finally {
    queryDb(
      "UPDATE glpi_sockets SET itemtype = 'NetworkEquipment', items_id = 1, networkports_id = 217 WHERE id = 86"
    );
    queryDb(
      "DELETE FROM glpi_plugin_patchpanel_portendpoints WHERE plugin_patchpanel_panelports_id = 2605 AND side = 'rear'"
    );
    queryDb(
      "INSERT INTO glpi_plugin_patchpanel_portendpoints (plugin_patchpanel_panelports_id, side, itemtype, items_id, cables_id, cable_color, cable_label, date_mod, date_creation) VALUES (2605, 'rear', 'Glpi\\\\Socket', 86, 0, NULL, NULL, NOW(), NOW())"
    );
  }

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`, {
    waitUntil: 'networkidle',
  });
  const panelName = `PP-LINK-UX-${Date.now()}`;
  await page.fill('input[name="name"]', panelName);
  await page.fill('input[name="port_count"]', '1');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');
  const panelId = Number(new URL(page.url()).searchParams.get('id'));

  await openTabByText(page, /Visual panel/i);
  await page.locator('.patchpanel-port').first().waitFor({ state: 'visible' });
  const portHref = await page.locator('.patchpanel-port').first().getAttribute('href');
  await page.goto(new URL(portHref, baseUrl).toString(), { waitUntil: 'networkidle' });
  const portId = Number(new URL(page.url()).searchParams.get('id'));

  await selectValue(page, 'rear_items_id', 254, '123');
  await selectValue(page, 'front_items_id', 359, 'NLH-F01-IDF-A-SW01 - Gi1/0/25');
  await page.locator('button[name="update"], input[name="update"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=-1`, {
    waitUntil: 'networkidle',
  });
  const duplicatePanelName = `PP-DUPLICATE-${Date.now()}`;
  await page.fill('input[name="name"]', duplicatePanelName);
  await page.fill('input[name="port_count"]', '1');
  await page.locator('button[name="add"], input[name="add"]').click();
  await page.waitForLoadState('networkidle');
  const duplicatePanelId = Number(new URL(page.url()).searchParams.get('id'));
  const duplicatePortId = Number(queryDb(
    `SELECT id FROM glpi_plugin_patchpanel_panelports WHERE plugin_patchpanel_panels_id = ${duplicatePanelId} LIMIT 1`
  ));
  await page.goto(`${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${duplicatePortId}`, {
    waitUntil: 'networkidle',
  });
  const duplicateToken = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  const duplicatePost = await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panelport.form.php`,
    {
      form: {
        id: String(duplicatePortId),
        label: 'Should not be saved',
        operational_state: 'active',
        media: 'copper',
        rear_items_id: '254',
        rear_cable_color: '',
        front_items_id: '359',
        front_cable_color: '',
        front_cable_label: '',
        update: '1',
        _glpi_csrf_token: duplicateToken,
      },
      maxRedirects: 0,
    },
  );
  const duplicateDirectPost = {
    status: duplicatePost.status(),
    duplicate_socket_count: queryDb(
      "SELECT COUNT(*) FROM glpi_plugin_patchpanel_portendpoints WHERE itemtype = 'Glpi\\\\Socket' AND items_id = 254"
    ),
    duplicate_networkport_count: queryDb(
      "SELECT COUNT(*) FROM glpi_plugin_patchpanel_portendpoints WHERE itemtype = 'NetworkPort' AND items_id = 359"
    ),
    second_port_endpoint_count: queryDb(
      `SELECT COUNT(*) FROM glpi_plugin_patchpanel_portendpoints WHERE plugin_patchpanel_panelports_id = ${duplicatePortId}`
    ),
    second_port_label: queryDb(
      `SELECT label FROM glpi_plugin_patchpanel_panelports WHERE id = ${duplicatePortId}`
    ),
  };
  await page.goto(`${baseUrl}/plugins/patchpanel/front/panel.form.php?id=${duplicatePanelId}`, {
    waitUntil: 'networkidle',
  });
  const duplicateCleanupToken = await page.locator('input[name="_glpi_csrf_token"]').last().inputValue();
  await page.request.post(
    `${baseUrl}/plugins/patchpanel/front/panel.form.php`,
    {
      form: {
        id: String(duplicatePanelId),
        purge: '1',
        _glpi_csrf_token: duplicateCleanupToken,
      },
      maxRedirects: 0,
    },
  );

  await page.goto(`${baseUrl}/front/socket.form.php?id=254`, { waitUntil: 'networkidle' });
  await openTabByText(page, /Patch panel routes/i);
  const socketCard = page.locator('.patchpanel-endpoint-route').filter({ hasText: panelName }).first();
  await socketCard.waitFor({ state: 'visible' });
  const socketActions = {
    manage: await socketCard.locator('a', { hasText: /Manage connection/i }).count(),
    disconnect: await socketCard.locator('button[name="disconnect_endpoint"]').count(),
    side: (await socketCard.innerText()).includes('Rear side: permanent cabling'),
    connection_details: (await socketCard.innerText()).includes('Connection details'),
  };
  await socketCard.locator('button[name="disconnect_endpoint"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${portId}`, {
    waitUntil: 'networkidle',
  });
  const afterRearDisconnect = {
    rear: await page.locator('select[name="rear_items_id"]').inputValue(),
    front: await page.locator('select[name="front_items_id"]').inputValue(),
  };

  await page.goto(`${baseUrl}/front/networkequipment.form.php?id=21`, {
    waitUntil: 'networkidle',
  });
  await openTabByText(page, /Patch panel routes/i);
  const deviceCard = page.locator('.patchpanel-endpoint-route').filter({ hasText: panelName }).first();
  await deviceCard.waitFor({ state: 'visible' });
  const deviceActions = {
    manage: await deviceCard.locator('a', { hasText: /Manage connection/i }).count(),
    disconnect: await deviceCard.locator('button[name="disconnect_endpoint"]').count(),
    side: (await deviceCard.innerText()).includes('Front side: patch cable'),
    connection_details: (await deviceCard.innerText()).includes('Connection details'),
  };
  await deviceCard.locator('button[name="disconnect_endpoint"]').click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/plugins/patchpanel/front/panelport.form.php?id=${portId}`, {
    waitUntil: 'networkidle',
  });
  const afterFrontDisconnect = {
    rear: await page.locator('select[name="rear_items_id"]').inputValue(),
    front: await page.locator('select[name="front_items_id"]').inputValue(),
  };

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
    panel_id: panelId,
    port_id: portId,
    socket_actions: socketActions,
    after_rear_disconnect: afterRearDisconnect,
    device_actions: deviceActions,
    after_front_disconnect: afterFrontDisconnect,
    ap001_device_disconnect: ap001DeviceDisconnect,
    ap001_rear_disconnect: ap001RearDisconnect,
    duplicate_direct_post: duplicateDirectPost,
    cleanup_status: cleanup.status(),
    browser_errors: errors,
  };
  console.log(JSON.stringify(result, null, 2));
  await browser.close();

  if (
    !result.socket_actions.side
    || !result.socket_actions.connection_details
    || result.socket_actions.manage !== 1
    || result.socket_actions.disconnect !== 1
    || result.after_rear_disconnect.rear !== '0'
    || result.after_rear_disconnect.front !== '359'
    || !result.device_actions.side
    || !result.device_actions.connection_details
    || result.device_actions.manage !== 1
    || result.device_actions.disconnect !== 1
    || result.after_front_disconnect.rear !== '0'
    || result.after_front_disconnect.front !== '0'
    || !result.ap001_device_disconnect.route_visible
    || !result.ap001_device_disconnect.connection_details
    || result.ap001_device_disconnect.action_count !== 1
    || result.ap001_device_disconnect.itemtype !== ''
    || result.ap001_device_disconnect.items_id !== '0'
    || result.ap001_device_disconnect.networkports_id !== '0'
    || result.ap001_rear_disconnect.rear_endpoint_count !== '0'
    || result.ap001_rear_disconnect.itemtype !== ''
    || result.ap001_rear_disconnect.items_id !== '0'
    || result.ap001_rear_disconnect.networkports_id !== '0'
    || ![200, 302, 303].includes(result.duplicate_direct_post.status)
    || result.duplicate_direct_post.duplicate_socket_count !== '1'
    || result.duplicate_direct_post.duplicate_networkport_count !== '1'
    || result.duplicate_direct_post.second_port_endpoint_count !== '0'
    || result.duplicate_direct_post.second_port_label !== 'Patch port 01'
    || ![200, 302, 303].includes(result.cleanup_status)
    || result.browser_errors.length
  ) {
    process.exitCode = 1;
  }
})();
