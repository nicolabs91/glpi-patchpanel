# PatchPanel for GLPI

PatchPanel is a GLPI plugin for documenting physical patch-panel cabling. It
keeps the patch panel itself as a GLPI asset and models every panel port with two
explicit sides:

- rear: the permanent cabling side, linked to a GLPI connection point;
- front: the patch cable side, linked to a GLPI network port.

This gives technicians one place to see which wall outlet, patch-panel port and
switch/router port belong together.

## Current features

- Create patch panels with a port count, row layout, media type, location and
  inventory number.
- Define reusable panel models so new panels can inherit their port count, rows
  and media type.
- View a visual patch-panel grid with free, partially connected and connected
  port states.
- Edit each panel port with a name/label, operational state, media type, rear
  connection point, front network port and patch cable color. The port name is
  also the cable label; there is no separate cable ID label.
- Follow the physical route from endpoint device to connection point, panel
  port, access switch and, when discoverable through GLPI network links, upstream
  core or gateway equipment.
- Search physical routes by panel, port, endpoint, device, switch, network port
  or firewall/router.
- Show which patch-panel routes depend on a GLPI network equipment item or
  network port.
- Place patch panels in GLPI racks through GLPI's native rack placement.
- Generate printable labels with QR codes for a continuous port range.
- Record route-affecting manual edits, CSV imports and CSV rollbacks in an audit
  history.

Administrative tools are available from the plugin settings page:

- CSV import with upload-first preview, validation, transactional apply and
  guarded rollback.
- Health check for expected route indexes, orphan records, duplicate endpoints,
  broken references and empty applied CSV batches.

## Compatibility

PatchPanel `0.1.2` targets GLPI 11 and PHP 8.2 or newer.

## Installation and upgrade

Back up the GLPI database and the existing plugin directory before installing or
upgrading. Copy this directory to `plugins/patchpanel`, then run:

```console
php bin/console plugin:install patchpanel
php bin/console plugin:activate patchpanel
```

For an upgrade, use `plugin:install --force patchpanel` to run additive schema
updates before activating the plugin.

Uninstall removes only PatchPanel's own tables. Keep a database backup when
historical audit or rollback data must be retained.

## Verification

Install the test dependencies with:

```console
npm ci
```

Run the full browser checkpoint suite with:

```console
npm run test:e2e
```

The suite covers the visible GLPI menu entry, panel creation, socket endpoint
handling, disconnect behavior, panel models, route search and impact views, CSV
import and rollback, labels, audit history, database-model checks, health checks
and WCAG 2.1 A/AA component checks.

Set `BROWSER=firefox` or use `npm run test:firefox` to run the same suite in
Firefox.

The database model is documented in `docs/database-model.md`.
