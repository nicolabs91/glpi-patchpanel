# PatchPanel for GLPI

PatchPanel models physical network cabling as two explicit sides of every panel
port:

- rear: permanent cabling to a GLPI remote endpoint / connection point;
- front: patch cable to a GLPI network port.

The main view is a visual panel. Every registered object in a physical route is
clickable, and upstream GLPI network-port links are followed toward the nearest
router or firewall.

## Release status

Version `0.1` targets GLPI 11 and PHP 8.2 or newer. It includes the new
schema, panel and port creation, endpoint uniqueness rules, visual statuses,
cable colors, reverse tabs, route navigation, health checks, impact analysis,
recoverable imports, QR labels, rack placement, and audit history.
Visual panels use at most 24 ports per row, matching a standard 24-port 1U
panel and showing a 48-port 2U panel as two rows.
Physical routes use consistent colors for each owning zone, with a textual
legend for end devices, remote endpoints, patch panels, access, core, and gateway.
The standard port form uses one cable ID and color without exposing GLPI's
separate native cable selector; existing native links remain preserved.
An end device selected on a GLPI remote endpoint is read from that endpoint's
network port, so disconnecting and reconnecting a device immediately updates
the physical route. If the endpoint keeps a device selected but no longer
has a LAN/network port selected, PatchPanel does not treat that device as an
active route endpoint. Disconnecting or moving the rear patch-panel side clears
the old endpoint device selection too, so the normal PatchPanel workflow does
not require a second cleanup step. If a stale endpoint device selection is
opened in GLPI's native socket form, PatchPanel also prevents GLPI's network
port dropdown from re-saving the old device as a connection.

The second checkpoint adds managed panel models. Selecting a model on a new
panel applies its port count, rows, and media. Existing panels require the explicit
`Apply model layout` checkbox, preventing accidental layout replacement.

The daily UI focuses on the cabling workflow. CSV import and health checks are
available from the plugin settings page instead of the normal PatchPanel list or
visual panel actions.

The route explorer searches across every visible step in a physical route,
including remote endpoints, endpoint devices, switch ports, switches, core equipment,
and firewalls. Infrastructure badges open an impact view listing every patch
panel route that depends on that GLPI network object.

CSV imports use an upload-first preview. Every panel, port, endpoint, state,
media value, duplicate, and existing assignment is validated before the apply
button appears. Applying is one database transaction and creates a rollback
batch; rollback is refused if an imported port was changed afterward.

Patch panels participate in GLPI's native rack placement. Printable labels can
be generated for any continuous port range; each label includes the panel,
port, location, and a QR code that opens the exact panel port in GLPI.

Route-affecting manual edits, CSV imports and rollbacks are
recorded in an immutable panel audit view with the responsible user and
machine-readable before/after snapshots.

Asset detail tabs show the same PatchPanel route card for remote endpoints, end
devices, network ports, switches, routers, and firewalls. The card includes
connection details for the rear permanent link, front patch link, and route
health, plus the same manage/disconnect actions regardless of which asset the
technician started from.

The database model is documented in `docs/database-model.md`. It defines the
GLPI-native boundaries, route-building tables, expected indexes, and integrity
rules used by the route, health, and import workflows.

The health check page verifies the expected route indexes, orphan records,
duplicate endpoints, invalid endpoint sides, broken socket/network-port
references, and empty applied CSV batches. It is intended as the last in-GLPI
gate before packaging or uploading a release.

## Installation and upgrade

Back up the GLPI database and the existing plugin directory before installing
or upgrading. Copy this directory to `plugins/patchpanel`, then run:

```console
php bin/console plugin:install patchpanel
php bin/console plugin:activate patchpanel
```

For an upgrade, use `plugin:install --force patchpanel` to run additive schema
updates before activating the plugin. Plugin uninstall removes only the
replacement plugin's own tables; use a database backup when historical audit or
rollback data must be retained.

## Verification

The browser checkpoints in `tests/e2e` start from the visible GLPI menu, verify
that the list has a readable add action, and create a panel through that
action. They also cover visual routes, models, route impact analysis, CSV import
and guarded rollback, rack/QR labels, audit history, health checks, and WCAG
2.1 A/AA component checks. Database-model and
health checkpoints
verify the expected route indexes and core integrity invariants. HTTP 4xx/5xx
responses fail the primary checkpoint.

Install the test dependencies with `npm ci`, then run `npm run test:e2e`.
Set `BROWSER=firefox` or use `npm run test:firefox` for Firefox.
