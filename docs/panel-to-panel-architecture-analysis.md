# Panel-to-panel links: architecture analysis

This document records round 1 of the panel-to-panel link development loop. It
describes the repository as it existed before introducing the canonical link
model. The separate technical design is documented in
`docs/panel-to-panel-links.md`.

## Existing endpoint model

`PluginPatchpanelPortEndpoint` stores one directional attachment for one panel
port:

- `rear` points to one `Glpi\Socket`;
- `front` points to one GLPI `NetworkPort`;
- a unique `port_side` index limits a panel port to one row per side;
- a unique `itemtype + items_id` index prevents one GLPI socket or network port
  from being assigned to several panel ports;
- `saveForPort()` validates item existence, access rights, endpoint type and
  uniqueness before updating both sides in one transaction.

The unfinished 0.2.1 working tree temporarily allowed another
`PluginPatchpanelPanelPort` as a polymorphic rear endpoint and wrote two
reciprocal endpoint rows. That proves the basic UI concept, but it is not the
right canonical model:

- one physical cable is represented by two database records;
- A-B and B-A cannot be protected by one normalized unique key;
- cable metadata has no single owner;
- health, audit and cleanup must infer whether both halves agree;
- route traversal becomes coupled to an endpoint table whose original purpose
  is linking one side to an external GLPI object;
- dropdown and test behavior already exposed incomplete reciprocal-state
  handling.

The reciprocal endpoint experiment must therefore be migrated to a dedicated,
single-record symmetric relation before release.

## Native GLPI network links

Each patch-panel port can own a managed shadow GLPI `NetworkPort`.
`PluginPatchpanelPortEndpoint` creates and cleans these shadow ports and keeps
GLPI's native `NetworkPort_NetworkPort` relation aligned with the selected
front switch/network port. This is intentionally separate from permanent rear
cabling. A panel-to-panel link must not delete or replace either port's front
endpoint or managed native link.

A managed shadow port may only have one native `Connected to` relation to a
real, active GLPI network port, and its parent panel must still be active.
Shadow-to-shadow relations and relations through deleted panels are rolled
back by the add hook. Health also reports legacy rows that violate these
invariants.

## Existing route reconstruction

`PluginPatchpanelRoute::buildForPort()` currently:

1. loads the panel port and parent panel;
2. loads its rear socket and front network port;
3. resolves the terminal device through the socket's `networkports_id`;
4. follows the front port's owning network equipment;
5. searches GLPI native network links for upstream infrastructure;
6. renders ordered route steps and records broken references.

Endpoint tabs find routes by socket, network port, owner and native-link
references. `PluginPatchpanelRouteExplorer` searches the resulting route steps
and builds impact results from panel ports. The current implementation has no
canonical symmetric link node, no stable visited set for panel-to-panel hops
and no link metadata to search.

## Audit, health, migration and lifecycle

- `PluginPatchpanelAudit::record()` stores an action, source, description,
  acting user and before/after JSON snapshots.
- Form, CSV and native network-link actions already use this audit mechanism.
- `PluginPatchpanelHealth` checks required indexes, endpoint uniqueness,
  broken references, native-link consistency, invalid layouts and import
  state.
- `PluginPatchpanelMigration::installSchema()` creates tables additively and
  has idempotent upgrade helpers.
- `hook.php` installs/upgrades through the migration class, registers database
  relations and removes only plugin-owned tables on uninstall.
- Purging a panel port cleans endpoint rows and its managed shadow network
  port. The link model must join this lifecycle without touching unrelated
  GLPI data.

## Entity and permission boundary

Panels are entity-aware GLPI objects. Panel ports inherit their effective
scope from the parent panel and use the existing `networking` right. Creating
or editing a symmetric link therefore requires visibility and update access to
both parent panels and both ports. A dropdown filter is only a convenience;
the model must repeat every permission, entity, deletion and occupancy check
server-side.

## CSV and test baseline

CSV preview/apply/rollback currently updates panel-port fields and rear/front
endpoints transactionally. It does not have a safe symmetric-link format.
Round 11 should initially choose the lower-risk strategy: do not create links
from CSV, but reject any import that would add a rear socket to a linked port.

The existing browser suite covers the menu, CRUD, sockets, native links,
routes, explorer, CSV, labels, audit, corrupt data, health and accessibility.
The former fixed NLH demo IDs, names and shared rack positions have been
replaced with uniquely named fixtures created for each checkpoint and removed
in `finally` cleanup. Both complete browser suites can therefore run against a
shared GLPI test database without depending on its pre-existing inventory.

## Design decision

Use a dedicated `PluginPatchpanelPanelPortLink` model backed by one normalized
`glpi_plugin_patchpanel_panelportlinks` row per physical cable.

This is safer than either alternative:

- extending `PortEndpoint` overloads a directional external-object relation
  with symmetric cable semantics;
- modelling the cable only through GLPI sockets/network ports would conflate
  permanent rear cabling with the existing front/native network connection
  and risks changing GLPI-owned relations.

The dedicated relation will consume the permanent rear side of both panel
ports, remain independent from both front endpoints, provide one owner for
cable metadata, and let database indexes enforce normalized identity in
addition to server-side validation.

## Round 1 verification and open risks

- Inspected all required model, migration, lifecycle, route, UI, JavaScript,
  documentation and E2E areas.
- Confirmed the repository remote is `nicolabs91/glpi-patchpanel`.
- Confirmed the active Docker stack loads the working tree directly.
- The original 14-checkpoint Chromium sweep exposed stale NLH fixture IDs
  against the HTL database. Those dependencies were later replaced with
  self-cleaning fixtures; the expanded 15-checkpoint Chromium and Firefox
  suites now pass as release gates.
- Directly exercised the unfinished reciprocal endpoint implementation. The
  two rows were written symmetrically, but the selected peer initially rendered
  as an empty dropdown label, reinforcing the decision not to release that
  architecture.
- Removed the temporary test relation after the check.

Primary risks for later rounds are route-cycle handling, entity validation,
atomic cleanup, avoiding N+1 queries in the visual grid and preserving native
front links during link removal.
