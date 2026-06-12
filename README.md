# PatchPanel for GLPI

PatchPanel models physical network cabling as two explicit sides of every panel
port:

- rear: permanent cabling to a GLPI wall outlet / connection point;
- front: patch cable to a GLPI network port.

The main view is a visual panel. Every registered object in a physical route is
clickable, and upstream GLPI network-port links are followed toward the nearest
router or firewall.

## Release status

Version `0.1.5` targets GLPI 11 and PHP 8.2 or newer. It includes the new
schema, panel and port creation, endpoint uniqueness rules, visual statuses,
cable colors, reverse tabs, route navigation, quality checks, impact analysis,
recoverable imports, QR labels, rack placement, and audit history.
Visual panels use at most 24 ports per row, matching a standard 24-port 1U
panel and showing a 48-port 2U panel as two rows.

The second checkpoint adds managed panel models and transaction-safe bulk
updates for a continuous port range. Selecting a model on a new panel applies
its port count, rows, and media. Existing panels require the explicit
`Apply model layout` checkbox, preventing accidental layout replacement.

The previous PatchPanel tables are treated as read-only legacy input. Installing
or uninstalling this version does not remove them.

The quality checkpoint adds an entity-aware overview for free, incomplete,
connected, broken, disabled, and faulty ports. Results can be filtered by
status or text and link directly to the affected panel port and physical route.

The route explorer searches across every visible step in a physical route,
including outlets, endpoint devices, switch ports, switches, core equipment,
and firewalls. Infrastructure badges open an impact view listing every patch
panel route that depends on that GLPI network object.

CSV imports use an upload-first preview. Every panel, port, endpoint, state,
media value, duplicate, and existing assignment is validated before the apply
button appears. Applying is one database transaction and creates a rollback
batch; rollback is refused if an imported port was changed afterward.

Patch panels participate in GLPI's native rack placement. Printable labels can
be generated for any continuous port range; each label includes the panel,
port, location, and a QR code that opens the exact panel port in GLPI.

Route-affecting manual edits, bulk changes, CSV imports and rollbacks, and
legacy migrations are recorded in an immutable panel audit view with the
responsible user and machine-readable before/after snapshots.

## Legacy migration

The migration page performs a read-only analysis first. It derives the new
front side from the connected infrastructure port, imports valid sides only,
marks duplicate or ambiguous endpoints as conflicts, and records every created
panel and port in a rollback batch. Rollback removes only records created by
that batch and never changes the legacy tables.

## Installation and upgrade

Back up the GLPI database and the existing plugin directory before installing
or upgrading. Copy this directory to `plugins/patchpanel`, then run:

```console
php bin/console plugin:install patchpanel
php bin/console plugin:activate patchpanel
```

For an upgrade, use `plugin:install --force patchpanel` to run additive schema
updates before activating the plugin. The installer never removes the legacy
PatchPanel tables. Plugin uninstall removes only the replacement plugin's own
tables; use a database backup when historical audit or rollback data must be
retained.

## Verification

The browser checkpoints in `tests/e2e` start from the visible GLPI menu, verify
that the list has a readable add action, and create a panel through that
action. They also cover visual routes, models and bulk transactions,
migration and rollback, quality and free-port search, route impact analysis,
CSV import and guarded rollback, rack/QR labels, audit history, and WCAG 2.1
A/AA component checks. HTTP 4xx/5xx responses fail the primary checkpoint.

Install the test dependencies with `npm ci`, then run `npm run test:e2e`.
Set `BROWSER=firefox` or use `npm run test:firefox` for Firefox.
