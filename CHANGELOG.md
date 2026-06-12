# Changelog

## 0.1.8 - 2026-06-12

- Fixed physical routes after an end device is disconnected from and
  reconnected to a GLPI wall outlet.
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

## 0.1.2 - 2026-06-12

- Restored the legacy/default `front/patchpanel.php` entry point used by stale
  GLPI menus and browser caches.
- Changed the primary browser checkpoint to enter PatchPanel through the
  visible GLPI menu and fail on HTTP 4xx/5xx responses.

## 0.1.1 - 2026-06-11

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
