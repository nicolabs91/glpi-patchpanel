const { execFileSync } = require('child_process');

function queryDb(sql) {
  return execFileSync('docker', [
    'exec',
    'glpi-db',
    'mariadb',
    '-uglpi',
    `-p${process.env.GLPI_DB_PASSWORD || 'glpi'}`,
    'glpi',
    '-N',
    '-e',
    sql,
  ], { encoding: 'utf8' }).trim();
}

function sqlString(value) {
  return `'${String(value).replaceAll('\\', '\\\\').replaceAll("'", "''")}'`;
}

function insertId(sql) {
  const output = queryDb(`${sql}; SELECT LAST_INSERT_ID();`);
  const id = Number(output.split(/\s+/).at(-1));
  if (!id) {
    throw new Error(`Fixture insert did not return an id: ${sql}`);
  }
  return id;
}

function createEndpointFixture(label = 'ENDPOINT', { withUpstream = false } = {}) {
  const token = `${label}-${process.pid}-${Date.now()}`;
  const names = {
    endpoint: `${token}-ENDPOINT`,
    endpointPort: `${token}-eth0`,
    socket: `${token}-SOCKET`,
    accessSwitch: `${token}-ACCESS-SWITCH`,
    frontPort: `${token}-ACCESS-01`,
    coreSwitch: `${token}-CORE-SWITCH`,
    accessUplink: `${token}-ACCESS-UPLINK`,
    coreDownlink: `${token}-CORE-DOWNLINK`,
    coreUplink: `${token}-CORE-UPLINK`,
    firewall: `${token}-FIREWALL`,
    firewallPort: `${token}-FIREWALL-DOWNLINK`,
  };
  const equipment = name => insertId(
    `INSERT INTO glpi_networkequipments
       (entities_id, is_recursive, name, is_deleted, is_template, is_dynamic,
        date_creation, date_mod)
     VALUES (0, 0, ${sqlString(name)}, 0, 0, 0, NOW(), NOW())`
  );
  const networkPort = (itemId, logicalNumber, name) => insertId(
    `INSERT INTO glpi_networkports
       (items_id, itemtype, entities_id, is_recursive, logical_number, name,
        instantiation_type, is_deleted, is_dynamic, date_creation, date_mod)
     VALUES (${itemId}, 'NetworkEquipment', 0, 0, ${logicalNumber},
        ${sqlString(name)}, 'NetworkPortEthernet', 0, 0, NOW(), NOW())`
  );

  const endpointId = equipment(names.endpoint);
  const accessSwitchId = equipment(names.accessSwitch);
  const endpointPortId = networkPort(endpointId, 1, names.endpointPort);
  const frontPortId = networkPort(accessSwitchId, 1, names.frontPort);
  const socketId = insertId(
    `INSERT INTO glpi_sockets
       (position, locations_id, name, socketmodels_id, wiring_side, itemtype,
        items_id, networkports_id, date_creation, date_mod)
     VALUES (0, 0, ${sqlString(names.socket)}, 0, 1, 'NetworkEquipment',
        ${endpointId}, ${endpointPortId}, NOW(), NOW())`
  );

  const fixture = {
    token,
    names,
    endpointId,
    accessSwitchId,
    endpointPortId,
    frontPortId,
    socketId,
  };
  if (withUpstream) {
    fixture.coreSwitchId = equipment(names.coreSwitch);
    fixture.firewallId = equipment(names.firewall);
    fixture.accessUplinkId = networkPort(accessSwitchId, 2, names.accessUplink);
    fixture.coreDownlinkId = networkPort(fixture.coreSwitchId, 1, names.coreDownlink);
    fixture.coreUplinkId = networkPort(fixture.coreSwitchId, 2, names.coreUplink);
    fixture.firewallPortId = networkPort(fixture.firewallId, 1, names.firewallPort);
    queryDb(
      `INSERT INTO glpi_networkports_networkports
         (networkports_id_1, networkports_id_2)
       VALUES
         (${fixture.accessUplinkId}, ${fixture.coreDownlinkId}),
         (${fixture.coreUplinkId}, ${fixture.firewallPortId})`
    );
  }
  return fixture;
}

function cleanupEndpointFixture(fixture) {
  if (!fixture) return;
  const networkPortIds = [
    fixture.endpointPortId,
    fixture.frontPortId,
    fixture.accessUplinkId,
    fixture.coreDownlinkId,
    fixture.coreUplinkId,
    fixture.firewallPortId,
  ].filter(Number);
  queryDb(
    `DELETE FROM glpi_networkports_networkports
     WHERE networkports_id_1 IN (${networkPortIds.join(',')})
        OR networkports_id_2 IN (${networkPortIds.join(',')})`
  );
  queryDb(
    `DELETE FROM glpi_plugin_patchpanel_portendpoints
     WHERE (itemtype = 'Glpi\\\\Socket' AND items_id = ${Number(fixture.socketId)})
        OR (itemtype = 'NetworkPort' AND items_id IN (${networkPortIds.join(',')}))`
  );
  queryDb(`DELETE FROM glpi_networkports WHERE id IN (${networkPortIds.join(',')})`);
  queryDb(`DELETE FROM glpi_sockets WHERE id = ${Number(fixture.socketId)}`);
  queryDb(
    `DELETE FROM glpi_networkequipments
     WHERE id IN (${[
       fixture.endpointId,
       fixture.accessSwitchId,
       fixture.coreSwitchId,
       fixture.firewallId,
     ].filter(Number).join(',')})`
  );
}

function purgePanel(panelId) {
  const id = Number(panelId);
  if (!id || queryDb(`SELECT COUNT(*) FROM glpi_plugin_patchpanel_panels WHERE id = ${id}`) === '0') {
    return;
  }
  const username = process.env.GLPI_USER || 'glpi';
  const password = process.env.GLPI_PASSWORD || 'glpi';
  const phpString = value => String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'");
  const code = `
chdir('/var/www/glpi');
require_once 'src/Glpi/Application/ResourcesChecker.php';
(new Glpi\\Application\\ResourcesChecker(getcwd()))->checkResources();
require_once 'vendor/autoload.php';
$kernel = new Glpi\\Kernel\\Kernel();
$kernel->boot();
$auth = new Auth();
$auth->login('${phpString(username)}', '${phpString(password)}', true);
$panel = new PluginPatchpanelPanel();
if ($panel->getFromDB(${id})) {
    $panel->delete(['id' => ${id}], true);
}
`;
  execFileSync('docker', ['exec', 'glpi-app', 'php', '-r', code]);
}

function createRackFixture(label = 'RACK') {
  const token = `${label}-${process.pid}-${Date.now()}`;
  const name = `${token}-42U`;
  const rackId = insertId(
    `INSERT INTO glpi_racks
       (name, entities_id, is_recursive, locations_id, manufacturers_id,
        racktypes_id, states_id, users_id, users_id_tech, number_units,
        is_template, is_deleted, dcrooms_id, room_orientation, max_power,
        mesured_power, max_weight, date_creation, date_mod)
     VALUES (${sqlString(name)}, 0, 0, 0, 0, 0, 0, 0, 0, 42, 0, 0, 0, 0,
        0, 0, 0, NOW(), NOW())`
  );
  return { rackId, name, token };
}

function cleanupRackFixture(fixture) {
  if (!fixture?.rackId) return;
  queryDb(`DELETE FROM glpi_items_racks WHERE racks_id = ${Number(fixture.rackId)}`);
  queryDb(`DELETE FROM glpi_racks WHERE id = ${Number(fixture.rackId)}`);
}

function createRouteFixture(label = 'ROUTE', { withUpstream = false } = {}) {
  const token = `${label}-${process.pid}-${Date.now()}`;
  const names = {
    endpoint: `${token}-ENDPOINT`,
    endpointPort: `${token}-eth0`,
    socket: `${token}-SOCKET`,
    panel: `${token}-PANEL`,
    panelPort: `${token}-PORT-01`,
    accessSwitch: `${token}-ACCESS-SWITCH`,
    frontPort: `${token}-ACCESS-01`,
    coreSwitch: `${token}-CORE-SWITCH`,
    accessUplink: `${token}-ACCESS-UPLINK`,
    coreDownlink: `${token}-CORE-DOWNLINK`,
    coreUplink: `${token}-CORE-UPLINK`,
    firewall: `${token}-FIREWALL`,
    firewallPort: `${token}-FIREWALL-DOWNLINK`,
  };

  const endpointId = insertId(
    `INSERT INTO glpi_networkequipments
       (entities_id, is_recursive, name, is_deleted, is_template, is_dynamic,
        date_creation, date_mod)
     VALUES (0, 0, ${sqlString(names.endpoint)}, 0, 0, 0, NOW(), NOW())`
  );
  const accessSwitchId = insertId(
    `INSERT INTO glpi_networkequipments
       (entities_id, is_recursive, name, is_deleted, is_template, is_dynamic,
        date_creation, date_mod)
     VALUES (0, 0, ${sqlString(names.accessSwitch)}, 0, 0, 0, NOW(), NOW())`
  );
  const endpointPortId = insertId(
    `INSERT INTO glpi_networkports
       (items_id, itemtype, entities_id, is_recursive, logical_number, name,
        instantiation_type, is_deleted, is_dynamic, date_creation, date_mod)
     VALUES (${endpointId}, 'NetworkEquipment', 0, 0, 1,
        ${sqlString(names.endpointPort)}, 'NetworkPortEthernet', 0, 0, NOW(), NOW())`
  );
  const frontPortId = insertId(
    `INSERT INTO glpi_networkports
       (items_id, itemtype, entities_id, is_recursive, logical_number, name,
        instantiation_type, is_deleted, is_dynamic, date_creation, date_mod)
     VALUES (${accessSwitchId}, 'NetworkEquipment', 0, 0, 1,
        ${sqlString(names.frontPort)}, 'NetworkPortEthernet', 0, 0, NOW(), NOW())`
  );
  const socketId = insertId(
    `INSERT INTO glpi_sockets
       (position, locations_id, name, socketmodels_id, wiring_side, itemtype,
        items_id, networkports_id, date_creation, date_mod)
     VALUES (0, 0, ${sqlString(names.socket)}, 0, 1, 'NetworkEquipment',
        ${endpointId}, ${endpointPortId}, NOW(), NOW())`
  );
  const panelId = insertId(
    `INSERT INTO glpi_plugin_patchpanel_panels
       (entities_id, is_recursive, name, locations_id,
        plugin_patchpanel_panelmodels_id, port_count, \`rows\`, media, is_deleted,
        date_creation, date_mod)
     VALUES (0, 0, ${sqlString(names.panel)}, 0, 0, 1, 1, 'copper', 0,
        NOW(), NOW())`
  );
  const panelPortId = insertId(
    `INSERT INTO glpi_plugin_patchpanel_panelports
       (plugin_patchpanel_panels_id, number, \`row\`, position, label,
        operational_state, media, date_creation, date_mod)
     VALUES (${panelId}, 1, 1, 1, ${sqlString(names.panelPort)},
        'active', 'copper', NOW(), NOW())`
  );
  const shadowPortId = insertId(
    `INSERT INTO glpi_networkports
       (items_id, itemtype, entities_id, is_recursive, logical_number, name,
        instantiation_type, is_deleted, is_dynamic, date_creation, date_mod)
     VALUES (${panelPortId}, 'PluginPatchpanelPanelPort', 0, 0, 0,
        ${sqlString(`${names.panel} - Port 1`)}, 'NetworkPortEthernet', 0, 0,
        NOW(), NOW())`
  );

  queryDb(
    `INSERT INTO glpi_plugin_patchpanel_portendpoints
       (plugin_patchpanel_panelports_id, side, itemtype, items_id, cables_id,
        cable_color, cable_label, date_creation, date_mod)
     VALUES
       (${panelPortId}, 'rear', 'Glpi\\\\Socket', ${socketId}, 0, NULL, NULL, NOW(), NOW()),
       (${panelPortId}, 'front', 'NetworkPort', ${frontPortId}, 0, NULL, NULL, NOW(), NOW())`
  );

  const upstream = {};
  if (withUpstream) {
    upstream.coreSwitchId = insertId(
      `INSERT INTO glpi_networkequipments
         (entities_id, is_recursive, name, is_deleted, is_template, is_dynamic,
          date_creation, date_mod)
       VALUES (0, 0, ${sqlString(names.coreSwitch)}, 0, 0, 0, NOW(), NOW())`
    );
    upstream.firewallId = insertId(
      `INSERT INTO glpi_networkequipments
         (entities_id, is_recursive, name, is_deleted, is_template, is_dynamic,
          date_creation, date_mod)
       VALUES (0, 0, ${sqlString(names.firewall)}, 0, 0, 0, NOW(), NOW())`
    );
    const networkPort = (itemId, logicalNumber, name) => insertId(
      `INSERT INTO glpi_networkports
         (items_id, itemtype, entities_id, is_recursive, logical_number, name,
          instantiation_type, is_deleted, is_dynamic, date_creation, date_mod)
       VALUES (${itemId}, 'NetworkEquipment', 0, 0, ${logicalNumber},
          ${sqlString(name)}, 'NetworkPortEthernet', 0, 0, NOW(), NOW())`
    );
    upstream.accessUplinkId = networkPort(accessSwitchId, 2, names.accessUplink);
    upstream.coreDownlinkId = networkPort(upstream.coreSwitchId, 1, names.coreDownlink);
    upstream.coreUplinkId = networkPort(upstream.coreSwitchId, 2, names.coreUplink);
    upstream.firewallPortId = networkPort(upstream.firewallId, 1, names.firewallPort);
    queryDb(
      `INSERT INTO glpi_networkports_networkports
         (networkports_id_1, networkports_id_2)
       VALUES
         (${upstream.accessUplinkId}, ${upstream.coreDownlinkId}),
         (${upstream.coreUplinkId}, ${upstream.firewallPortId})`
    );
  }
  queryDb(
    `INSERT INTO glpi_networkports_networkports
       (networkports_id_1, networkports_id_2)
     VALUES (${frontPortId}, ${shadowPortId})`
  );

  return {
    token,
    names,
    endpointId,
    accessSwitchId,
    endpointPortId,
    frontPortId,
    socketId,
    panelId,
    panelPortId,
    shadowPortId,
    ...upstream,
  };
}

function cleanupRouteFixture(fixture) {
  if (!fixture) return;
  const networkPortIds = [
    fixture.endpointPortId,
    fixture.frontPortId,
    fixture.shadowPortId,
    fixture.accessUplinkId,
    fixture.coreDownlinkId,
    fixture.coreUplinkId,
    fixture.firewallPortId,
  ]
    .filter(Number);
  queryDb(
    `DELETE FROM glpi_networkports_networkports
     WHERE networkports_id_1 IN (${networkPortIds.join(',')})
        OR networkports_id_2 IN (${networkPortIds.join(',')})`
  );
  queryDb(
    `DELETE FROM glpi_plugin_patchpanel_portendpoints
     WHERE plugin_patchpanel_panelports_id = ${Number(fixture.panelPortId)}`
  );
  queryDb(
    `DELETE FROM glpi_plugin_patchpanel_panelportlinks
     WHERE panelports_id_a = ${Number(fixture.panelPortId)}
        OR panelports_id_b = ${Number(fixture.panelPortId)}`
  );
  queryDb(`DELETE FROM glpi_networkports WHERE id IN (${networkPortIds.join(',')})`);
  queryDb(`DELETE FROM glpi_sockets WHERE id = ${Number(fixture.socketId)}`);
  queryDb(
    `DELETE FROM glpi_plugin_patchpanel_panelports
     WHERE id = ${Number(fixture.panelPortId)}`
  );
  queryDb(
    `DELETE FROM glpi_plugin_patchpanel_panels
     WHERE id = ${Number(fixture.panelId)}`
  );
  queryDb(
    `DELETE FROM glpi_networkequipments
     WHERE id IN (${[
       fixture.endpointId,
       fixture.accessSwitchId,
       fixture.coreSwitchId,
       fixture.firewallId,
     ].filter(Number).join(',')})`
  );
}

module.exports = {
  cleanupEndpointFixture,
  cleanupRackFixture,
  cleanupRouteFixture,
  createEndpointFixture,
  createRackFixture,
  createRouteFixture,
  insertId,
  purgePanel,
  queryDb,
  sqlString,
};
