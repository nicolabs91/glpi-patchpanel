# Changelog

## 0.2.0 - 2026-07-23

- Restored the rear Remote endpoint selector to GLPI sockets only, matching the
  behavior before 0.1.4; wall outlets remain available as sockets.
- Removed the Inventory number field from patch-panel create and edit forms.
- Prevented the optional rack-layout lookup from blocking GLPI while adding a
  patch panel to a rack.
- Kept legacy 0.1.4 device-port endpoints safe to disconnect or replace without
  exposing them as selectable rear endpoints.
- Removed hardcoded database credentials from the browser regression scripts.

## 0.1.4 - 2026-07-20

- Replaced the wall-outlet-only remote endpoint field with one searchable,
  grouped selector for available GLPI wall outlets and device network ports.
- Excluded deleted, inaccessible, managed PatchPanel shadow ports and endpoints
  already assigned to another panel port while preserving existing selections.
- Kept existing 0.1.3 wall-outlet connections and front-side patch cable
  behavior compatible during upgrades.

## 0.1.3 - 2026-07-16

- Made patch panels fully rackable in GLPI 11 by using the datacenter model
  contract and adding the required rack dimensions to panel models.
- Added a safe upgrade migration for rack model fields and legacy PatchPanel
  rack relations, including cleanup of orphaned legacy relations.
- Fixed 48-port 2U models so they occupy two rack units.
- Prevented `Item is out of rack bounds` when a multi-unit patch panel is
  selected at the top of a rack by automatically choosing the highest valid
  starting unit.
- Expanded the browser regression test to create a 2U panel, select it from a
  rack, verify the position correction, save it, and check for browser errors.

## 0.1.2 - 2026-07-12

- Always displayed the complete physical route, including core and gateway
  steps, instead of hiding upstream infrastructure behind a `...` control.
- Hid the internal managed shadow-network-port link from GLPI's native
  connected-to display. The real patch-panel port remains the single visible
  and clickable physical destination.
- Used the patch-panel icon for the `Patch panel routes` tab on devices and
  network ports.
- Removed the separate Cable ID label from the panel-port form and CSV import.
  The panel-port name/label is now the single label used to identify the cable.
- Prevented access-switch downlinks and endpoint devices from being presented as
  upstream core infrastructure in physical routes.
- Preserved per-port media overrides when a panel is renamed or otherwise saved
  without an explicit panel media change.
- Rejected CSV files with duplicate column names instead of silently using the
  last duplicate value.
- Removed hidden GLPI shadow network ports before shrinking a panel and added a
  health check for shadow ports whose PatchPanel port no longer exists.
- Simplified switch network-port names to their zero-padded port number so GLPI
  consistently displays the switch name followed by `01`, `02`, and so on.
- Shortened the Docker example switch asset names to their technical identifiers
  so route labels stay compact, for example `NLH-F01-IDF-A-SW01 · 01`.
- Renamed every real switch network port to the full short switch identifier plus
  its zero-padded number, for example `NLH-F01-IDF-A-SW01 01`, without repeating
  the owner name in PatchPanel route labels.
- Fixed GLPI's native `Connected to` relation so a switch/router port points to
  the patch-panel port as its first physical hop instead of skipping directly
  to the network port behind the wall outlet. Existing native links are rewired
  during plugin installation or upgrade.

## 0.1.1 - 2026-07-04

- Added a corrupt-data e2e checkpoint that injects broken socket references,
  broken network-port references and invalid endpoint types, verifies the health
  warnings, and proves route views keep rendering instead of crashing.
- Added a route-consistency e2e checkpoint that compares the same route across
  the panel-port form, route explorer, endpoint tab and impact filter.
- Added a front-only switch-port sync path: when a panel port is patched to a
  switch/router port before a rear socket has a GLPI network port, PatchPanel
  now creates a GLPI network port for the panel port and links it natively so
  the switch port form also shows the PatchPanel connection.
- Gave panel ports a GLPI object display name based on the patch-panel name and
  port number, for example `PP-L1-IDF-A / Port 1`, so the form header no longer
  shows `N/A`.
- Synced external GLPI native network-port disconnects back into PatchPanel:
  disconnecting the switch/router port now removes the matching PatchPanel
  front endpoint for both front-only and rear+front patch links.
- Synced external GLPI native network-port connects into PatchPanel as well:
  connecting a switch/router port to a known panel shadow port or rear socket
  network port now fills the matching PatchPanel front endpoint automatically.
- Expanded the native-link health check so front-only shadow network-port drift
  is detected as well as rear-socket native-link drift.
- Fixed route building so PatchPanel's native front-to-socket endpoint link is
  not counted as an upstream/core network edge and cannot loop the physical
  route back to the endpoint device.
- Resynced native GLPI `Connected to` links when a socket endpoint is saved,
  and added a health check for missing native links so PatchPanel and the GLPI
  switch-port overview cannot silently drift apart.
- Fixed e2e fixture cleanup that could remove demo native links while testing
  temporary direct SQL routes.
- Removed the visible `Apply model layout` checkbox from the patch-panel form;
  selected models still apply their port count, rows, and media on save.
- Hid the patch-panel `Number of ports` field when a model is selected, keeping
  the model as the visible source of the port count.
- Synced PatchPanel front/rear saves with GLPI's native network-port
  `Connected to` relation, and only mark a panel port connected when the rear
  socket has a real endpoint network port plus a front switch/router port.
- Simplified the visual-panel status set to `Free`, `Not connected`, and
  `Connected`; removed separate `Broken reference`, `Out of service`, and
  `Fault` statuses from the daily panel workflow.
- Removed the `Print QR labels` action from the visual panel quick actions.
- Replaced the visual-panel `Not connected` warning icon with the neutral
  dashed-circle icon and kept the legend badge from squeezing the label.
- Collapsed physical-route device and network-port pairs into one route badge,
  for example `Device · eth0` or `Switch · Gi1/0/24`, while preserving the
  underlying GLPI references for search and impact analysis.
- Matched the visual-panel `Not connected` legend and port tile fill/border
  colors so both read as the same light-gray state.
- Renamed the visual-panel incomplete state to `Not connected` and changed it
  from yellow to light gray.
- Removed the patch-panel serial-number form field, rear-side cable color
  field, and oversized comment textareas from the normal editing workflow.
- Fixed comment textareas printing a stray `1` after the field.
- Pointed plugin CSS and JavaScript hooks at the tracked `public/` assets.
- Removed standalone bulk port management from the visual panel workflow.
- Kept model-based port creation, per-port edits, CSV import, labels, routes,
  audit history, and health checks intact.
- Set a distinct connection-point route background so the physical-route
  legend never reads as white on GLPI's default background.

## 0.1 - 2026-06-28

- Added faster panel and quality status calculations based on endpoint counts
  instead of repeated full-route reconstruction.
- Removed the standalone cabling quality dashboard from the GLPI UI because
  patch-panel registration is manual data, not live infrastructure telemetry.
- Removed the legacy PatchPanel analysis page from the active UI and test flow.
- Moved CSV import and health check links to the plugin settings page so they
  no longer appear in the daily PatchPanel list or visual panel actions.
- Reused already-built route steps in the route explorer result rendering
  instead of rebuilding every displayed route a second time.
- Added request-local route caches for repeated GLPI object, owner, neighbour,
  and router/firewall lookups during route-heavy views.
- Added route endpoint actions for disconnecting one panel-port side or the
  device selected on a GLPI remote endpoint.
- Added quick workflow links from the visual panel and port form to route
  search, audit history, labels, neighboring ports, and the visual panel.
- Documented the GLPI-native database model, expected indexes, route-building
  boundaries, and integrity rules.
- Added a database-model browser checkpoint that verifies route indexes and
  core endpoint integrity invariants.
- Added an in-GLPI health check page for release readiness: expected indexes,
  orphan records, duplicate endpoints, broken references, invalid endpoint
  sides, empty applied CSV batches, and repair suggestions.
- Stopped treating a remote endpoint's selected device as an active end device
  when the endpoint no longer has a selected LAN/network port.
- Added uniform asset-detail route cards with rear link, front link and route
  health details plus consistent manage/disconnect actions.
- Automatically clears the endpoint device/LAN selection when the rear
  patch-panel side is disconnected or moved to another endpoint.
- Removed the panel-port "End device on endpoint" helper row because the
  terminal belongs in the physical route; stale GLPI endpoint device selections
  are shown on endpoint route cards instead of the patch-panel
  port form.
- Prevented GLPI's native socket form from re-saving an old device/network-port
  selection when the socket database row has already been cleared to no LAN
  port.
- Collapsed upstream core/firewall route steps behind a `...` control so the
  normal route view stops at the access switch unless the full path is needed.

## 0.1.8 - 2026-06-12

- Fixed physical routes after an end device is disconnected from and
  reconnected to a GLPI remote endpoint.
- Resolve the terminal device and its port from the rear socket's selected
  network port, while retaining legacy direct network-port links as fallback.

## 0.1.7 - 2026-06-12

- Removed the redundant native GLPI cable selector from the standard panel-port
  form while retaining the simpler cable ID and color fields.
- Preserved existing native GLPI cable links when a panel port is edited.
- Added a browser regression check that keeps the redundant selector hidden.

## 0.1.6 - 2026-06-12

- Added owner-consistent route colors for end devices, connection points,
  patch panels, access switches, core infrastructure, and firewall/routers.
- Added a textual route-color legend so meaning is not conveyed by color alone.
- Added browser assertions for the full semantic zone sequence.

## 0.1.5 - 2026-06-12

- Restored the physical panel convention of 24 ports per visual row: standard
  24-port 1U panels use one row and 48-port 2U panels use two rows.
- Updated the browser layout assertion to require 24 columns.

## 0.1.4 - 2026-06-12

- Limited the visual panel to 12 ports per row for a clearer 2 × 12 layout on
  standard 24-port panels.
- Added a browser assertion for the 12-column visual layout.
- Kept the primary browser checkpoint isolated from the persistent example
  panel by using a separate access-switch route.

## 0.1.3 - 2026-06-12

- Added a visible `Add patch panel` action to the empty panel list.
- Replaced low-contrast outline actions with readable solid buttons.
- Extended browser and accessibility checkpoints to start from the empty list
  and create a panel through the visible add action.

## 0.1.2 prerelease notes - 2026-06-12

- Restored the legacy/default `front/patchpanel.php` entry point used by stale
  GLPI menus and browser caches.
- Changed the primary browser checkpoint to enter PatchPanel through the
  visible GLPI menu and fail on HTTP 4xx/5xx responses.

## 0.1.1 prerelease notes - 2026-06-11

- Added reproducible Chromium/Firefox browser tests and automated WCAG 2.1
  component checks.
- Associated visible labels with bulk, quality, and QR-range inputs.
- Replaced low-contrast route and label actions with accessible primary buttons.

## 0.1.0 - 2026-06-11

- Rebuilt the plugin around panels, panel ports, and explicit front/rear endpoints.
- Added a visual, accessible panel grid with status and cable-color separation.
- Added transaction-safe endpoint updates and database uniqueness constraints.
- Added clickable physical routes through GLPI network ports to router/firewall.
- Preserved all old PatchPanel tables as non-destructive migration input.
- Added editable panel models with explicit model application rules.
- Added transaction-safe bulk labels, operational state, and media updates.
- Added read-only legacy analysis, conflict-aware import, migration reports,
  idempotent mapping records, and batch rollback.
- Added an entity-aware cabling quality and free-port dashboard with status and
  text filters plus direct links to panel ports and routes.
- Added full-route search and infrastructure impact analysis based on the same
  canonical, clickable physical route representation.
- Added CSV upload preview, endpoint and duplicate validation, transaction-safe
  bulk linking, immutable before/after snapshots, and guarded batch rollback.
- Added native GLPI rack placement and printable panel-port QR label sheets.
- Added panel audit history for manual, bulk, CSV, and migration changes.
