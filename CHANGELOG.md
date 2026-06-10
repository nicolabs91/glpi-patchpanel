# Changelog

## 0.1.0-dev

- Rebuilt the plugin around panels, panel ports, and explicit front/rear endpoints.
- Added a visual, accessible panel grid with status and cable-color separation.
- Added transaction-safe endpoint updates and database uniqueness constraints.
- Added clickable physical routes through GLPI network ports to router/firewall.
- Preserved all old PatchPanel tables as non-destructive migration input.
- Added editable panel models with explicit model application rules.
- Added transaction-safe bulk labels, operational state, and media updates.
- Added read-only legacy analysis, conflict-aware import, migration reports,
  idempotent mapping records, and batch rollback.
