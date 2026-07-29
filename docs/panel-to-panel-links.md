# Panel-to-panel links

## Functional meaning

A panel-to-panel link represents one permanent physical cable between two
different patch-panel ports, for example:

`MDF-PP01 / Port 1 ↔ IDF-PP02 / Port 24`

The relation is symmetric. It is stored once and is shown identically from
either port. It may represent a connection between racks, technical rooms,
floors or buildings.

## Relationship to rear and front sides

The link consumes the permanent `rear` side of both panel ports:

- a linked port cannot also have a rear `Glpi\Socket` endpoint;
- a port with a rear socket cannot be linked to another panel port;
- each port may keep or receive its own `front` GLPI `NetworkPort`;
- deleting the link releases only the two rear sides;
- deleting the link never removes a front endpoint, managed shadow network
  port or GLPI-native network-port relation.

This preserves the existing endpoint meanings and supports routes such as:

`Device → Socket → Panel A / 1 ↔ Panel B / 24 → Switch port → Network`

## Canonical data model

Class:

`PluginPatchpanelPanelPortLink`

Table:

`glpi_plugin_patchpanel_panelportlinks`

Fields used by the first implementation:

- `id`
- `panelports_id_a`
- `panelports_id_b`
- `cable_label`
- `media_type`
- `cable_color`
- `length`
- `comment`
- `is_active`
- `date_creation`
- `date_mod`

The canonical model retains optional cable metadata for compatibility,
migration, audit snapshots and future integrations. The normal rear-side form
does not expose these fields: its only additional control is the linked
patch-panel port. A compact-form save preserves existing metadata rather than
clearing it. An entity column is deliberately not duplicated: entity
visibility is derived from both linked parent panels.

Endpoint IDs are always normalized:

- the lower panel-port ID is stored in `panelports_id_a`;
- the higher ID is stored in `panelports_id_b`.

Indexes:

- unique `link_pair (panelports_id_a, panelports_id_b)`;
- `panelports_id_a`;
- `panelports_id_b`;
- `is_active`.

The pair index prevents A-B and B-A duplicates. Server-side validation also
enforces one active link per port; health checks detect legacy or manipulated
rows that violate this rule.

## Integrity and access rules

Creating or changing a link requires all of the following:

1. Both IDs are positive, different and refer to existing panel ports.
2. Both parent panels exist and are not deleted.
3. The acting user can view and update both ports and both parent panels.
4. Both panels are visible through the current GLPI entity scope.
5. Neither port has a rear socket endpoint.
6. Neither port belongs to another active panel-to-panel link.
7. An update cannot silently replace a peer when that would discard another
   physical relation.
8. Front endpoints remain untouched.

The model repeats these checks for every add/update request. Form dropdowns,
CSV preview and client-side controls are not security boundaries.

## Lifecycle and transactions

Link creation, metadata update, removal and audit recording occur inside the
calling transaction. Removing a port or panel calls `deleteForPanelPort()` for
every affected port before deleting plugin-owned rows. Cleanup records a
specific audit event where a valid parent panel is still available.

Install and upgrade are additive and idempotent. Uninstall removes the link
table because it is plugin-owned, but never removes GLPI sockets, network
ports, native links, panels from another plugin or core data.

## User interface

The rear-side selector has three explicit states:

- `Not connected`
- `GLPI connection point`
- `Another patch panel`

For another patch panel, the form presents one additional searchable field:
the available target patch-panel port. Its option label includes the parent
panel, so a separate panel field is unnecessary. Cable label, cable color,
media type, length and comment are deliberately absent from this workflow.

Only visible, non-deleted panels and valid candidate ports are selectable.
Occupied ports are not accepted server-side even if a request is manipulated.
The saved view shows `Connected to: <panel> / Port <number>` with links when
read access permits, plus a confirmed `Disconnect link` action.

The visual panel grid shows a textual `Cross-connected` state, peer name and
port number. Color supplements this information but is never its only signal.
Link data for all visible tiles is fetched set-wise.

## Route traversal

A panel-to-panel cable is an explicit route hop. Traversal can enter from
either endpoint and continue through the peer port. Stable visited keys are
used:

- `panelport:<id>`
- `panelportlink:<id>`
- `socket:<id>`
- `networkport:<id>`

Traversal has a finite maximum depth in addition to the visited set. Broken
peers and cycles produce route warnings rather than recursion or repeated
steps. Existing socket-only routes preserve their current ordering and
semantics.

Multiple panel hops are allowed when every hop is valid. The route explorer
searches both panels, both ports, cable metadata, sockets, front ports and
network equipment. A symmetric relation yields one canonical result, not a
duplicate result per direction.

## Audit and health

Audit events cover:

- creation;
- metadata update;
- explicit removal;
- cleanup caused by a port or panel purge;
- CSV changes if link import is added later.

Snapshots contain both IDs, panel names, port numbers and normalized cable
metadata, but never an unfiltered request payload.

Health checks cover:

- missing A or B ports;
- deleted parent panels;
- self-links;
- non-normalized endpoint order;
- duplicate pairs;
- more than one active link per port;
- rear socket plus active link;
- missing indexes;
- orphan rows;
- cleanup failures;
- route cycles.

Health output is descriptive and non-destructive.

## CSV strategy

The first release uses the lower-risk strategy: CSV does not create, edit or
delete panel-to-panel links. This limitation is documented in the import UI
and README.

CSV preview and apply must reject a rear-socket change for a linked port.
Rollback must also respect the same centralized validation and cannot restore
a conflicting socket behind an active link, even when the imported port fields
themselves have not changed since the import. A later version can add peer and
link columns without changing the canonical model; this must not implicitly
expand the compact rear-side UI.

The rollback-safe lifecycle checkpoint covers creation, lookup from both
endpoints, peer reassignment, release of the old peer and deletion. It also
proves that a compact-form save without metadata fields preserves any existing
canonical metadata instead of clearing it.

## Performance boundaries

- Link lookup for one port uses indexed A/B predicates.
- Grid and health views load links set-wise.
- Route traversal loads only reachable nodes and has visited/depth guards.
- Route explorer requires a meaningful query or scoped object and does not
  reconstruct every route for an empty search.
- Parent panel permission checks are cached within one request.

## Migration from the unfinished reciprocal endpoint experiment

The unreleased working tree may contain reciprocal rear `PortEndpoint` rows
whose `itemtype` is `PluginPatchpanelPanelPort`. The additive migration:

1. identifies only complete reciprocal pairs;
2. normalizes each pair;
3. creates one canonical link when neither port has a socket conflict;
4. records/report conflicts instead of overwriting data;
5. removes migrated experimental rows only after the link insert succeeds;
6. remains safe to run repeatedly.

No released version depends on those experimental rows, but handling them
protects the active local test installation.
