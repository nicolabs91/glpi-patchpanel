# PatchPanel database model

PatchPanel stores only the extra cabling intent that GLPI does not model as a
patch-panel front/rear workflow. Devices, remote endpoints, switch ports, racks,
locations, entities, profiles, history, and visibility stay GLPI-native.

## Canonical route

A physical route is built from these records:

1. `glpi_plugin_patchpanel_panels`
   - One GLPI asset-like patch panel.
   - Uses GLPI `entities_id`, `is_recursive`, `locations_id`, soft delete, rack
     placement, and standard object permissions.
2. `glpi_plugin_patchpanel_panelports`
   - One physical panel port.
   - `plugin_patchpanel_panels_id + number` is unique.
   - `operational_state` describes the physical port state.
3. `glpi_plugin_patchpanel_portendpoints`
   - One endpoint per panel-port side.
   - `side = rear` points to a GLPI `Glpi\Socket`.
   - `side = front` points to a GLPI `NetworkPort`.
   - `plugin_patchpanel_panelports_id + side` is unique.
   - `itemtype + items_id` is unique so the same socket or network port cannot
     be assigned to two panel ports.
4. GLPI sockets
   - Permanent room endpoint, direct device endpoint, or connection point records.
   - If a socket has `networkports_id`, that network port is the preferred
     terminal device port.
   - If no network port is selected, `itemtype + items_id` is retained as a
     fallback device reference.
5. GLPI network ports and network-port links
   - Front patching points to a GLPI `NetworkPort`.
   - Upstream route discovery follows GLPI `glpi_networkports_networkports`
     links between `NetworkEquipment` ports toward a router, firewall, or
     gateway.

This means PatchPanel should never duplicate GLPI device ownership,
network-port ownership, socket-device assignment, rack placement, locations, or
entity visibility.

## Indexes expected by route lookups

PatchPanel depends on these index shapes:

- `glpi_plugin_patchpanel_portendpoints.port_side`
  - `(plugin_patchpanel_panelports_id, side)`
  - Used when rendering or updating one panel port.
- `glpi_plugin_patchpanel_portendpoints.endpoint`
  - `(itemtype, items_id)`
  - Used by reverse tabs from sockets and network ports.
- `glpi_plugin_patchpanel_panelports.panel_number`
  - `(plugin_patchpanel_panels_id, number)`
  - Used by panel rendering and neighbor navigation.
- `glpi_plugin_patchpanel_panelports.panel_layout`
  - `(plugin_patchpanel_panels_id, row, position)`
  - Used by the visual panel order.
- `glpi_networkports.item`
  - `(itemtype, items_id)`
  - Used to find all ports owned by a GLPI object.
- `glpi_sockets.item`
  - `(itemtype, items_id)`
  - Used to find sockets directly attached to a GLPI object.
- `glpi_sockets.networkports_id`
  - Used to find sockets attached through a GLPI network port.

If a future feature adds a new lookup direction, add the index and a database
checkpoint before relying on it in the UI.

## Query rules

- Empty route-explorer searches must not reconstruct every route.
- Panel overview counts should be set-based, not per-port route reconstruction.
- The visual panel may build the route once per visible port because cable color
  and route-derived status are both shown on the same tile.
- Reverse object tabs should first collect candidate endpoint references via
  indexed `itemtype + items_id` or GLPI-owned indexed relations, then render only
  those routes.
- CSV import and disconnect actions should run in explicit transactions and
  record audit snapshots.

## Data integrity rules

- A panel port can have at most one `rear` endpoint and one `front` endpoint.
- A socket or network port can be used by at most one panel endpoint.
- Broken references must remain detectable by health checks; the visual panel
  keeps the daily status set focused on free, not connected, and connected
  ports.
- Deleting a panel port deletes its plugin endpoint rows.
- Deleting or purging test panels must leave no plugin endpoints or active
  import batches behind.

## GLPI-native boundaries

- Use `Glpi\Socket` for remote endpoints and room connection points.
- Use `NetworkPort` for switch/router/firewall/front patching points.
- Use GLPI socket `networkports_id` to resolve the actual terminal device port.
- Link the front switch/router port natively to the managed shadow port of the
  PatchPanel port. GLPI's `Connected to` field therefore shows the first
  physical hop; the rear socket's terminal device port is route data, not the
  native peer of the switch/router port.
- Preserve native GLPI cable links when editing a PatchPanel port and color
  fields.
- Treat `NetworkPort` rows owned by `PluginPatchpanelPanelPort` as managed
  shadow records: remove them before deleting their panel port and report any
  orphan shadow rows in the health check.
- Keep rights based on GLPI `networking` permissions unless a later feature
  introduces explicit profile handling.
- Apply entity restrictions in list, health, import, and explorer views.
