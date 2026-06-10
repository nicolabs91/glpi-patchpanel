# PatchPanel for GLPI

PatchPanel models physical network cabling as two explicit sides of every panel
port:

- rear: permanent cabling to a GLPI wall outlet / connection point;
- front: patch cable to a GLPI network port.

The main view is a visual panel. Every registered object in a physical route is
clickable, and upstream GLPI network-port links are followed toward the nearest
router or firewall.

## Development status

Version `0.1.0-dev` is the first vertical checkpoint. It includes the new
schema, panel and port creation, endpoint uniqueness rules, visual statuses,
cable colors, reverse tabs, and route navigation.

The previous PatchPanel tables are treated as read-only legacy input. Installing
or uninstalling this version does not remove them.
