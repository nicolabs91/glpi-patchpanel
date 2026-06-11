# Changelog

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
