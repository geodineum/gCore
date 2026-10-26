# Changelog

All notable changes to this project are documented here. The format follows Keep a Changelog.

## [0.2.0] — unreleased

### Fixed: Comms settings kept what the page does not show

- The wp-admin Comms page saved only its own sections and replaced the record, so an autosave erased `addressed` (the types COMMS may send to one person: the sign-in mail, the morning digest). Sections the form does not send are now kept.
- The page shows and edits the addressed types and the daily cap.

### Breaking: the one topology

- Reads the constellation by shape and discovers services by name on the one topology.
- Registration config is a shape plus axis words; capability-space geometry wording and the template-discovery wrappers over retired client methods are removed.
- Requires gNode-Client 0.2.0 and a daemon on the one topology.

