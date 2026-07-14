# gDash — Geodineum Dashboard

> Operator-facing observability surface for the Geodineum ecosystem.
> Substrate: gCore Module + WordPress MU-plugin (`gcore-mu/`).

## Overview

The Geodineum Dashboard (`gDash` for short) is the WordPress-admin surface
operators use to observe and debug a Geodineum installation. It runs entirely
inside the gCore MU-plugin path (`/wp-content/mu-plugins/gcore-mu.php`) so
every Geodineum site gets it for free, with no plugin activation required.

Pages shipped:

| Slug                  | Page                          | Purpose                                                      |
| --------------------- | ----------------------------- | ------------------------------------------------------------ |
| `gcore-dashboard`     | Status (extended)             | gNode + framework + topology + sites + cache health, at a glance |
| `gcore-topology`      | Topology 3D                   | Three.js 3D viz of the 23-dim service topology               |
| `gcore-sites`         | Sites                         | Per-site card grid + detail (env, theme, cache hit-rate)     |
| `gcore-services-list` | Services                      | Flat tabular list with per-service request counters          |
| `gcore-streams-keys`  | Streams & Keys                | SCAN-cursor explorer with shape-aware render                 |
| `gcore-acl-inspector` | ACL Inspector                 | Read-only ACL LIST view                                      |
| `gcore-service-logs`  | Service Logs                  | journalctl tail per allowlisted Geodineum systemd unit       |
| `gcore-actions`       | Actions (placeholder)         | Planned: every CLI subcommand → admin form                   |
| `gcore-diagnostics`   | Diagnostics                   | Component-level self-test                                    |
| `geodineum-schemas`   | Config Schemas                | Ecosystem-wide config_schema browser                         |
| `gcore-comms-*`       | COMMS                         | Notification settings                                        |

## Panel-registration contract

The dashboard is the integration surface for extension modules. Each module
ships its own admin page(s) by registering them with the dashboard.

### Minimum example

```php
<?php
// Inside your module's bootstrap:
add_action('plugins_loaded', function () {
    if (!class_exists('gDash')) {
        return; // dashboard not present — degrade silently
    }
    gDash::register_panel([
        'slug'     => 'myext-overview',
        'title'    => __('MyExt Overview', 'myext'),
        'callback' => ['\\Vendor\\MyExt\\Admin', 'renderOverview'],
    ]);
}, 20);
```

### Full signature

```php
gDash::register_panel([
    /** Required — unique, lowercase-with-hyphens. */
    'slug'       => 'string',

    /** Required — page <h1> + browser title. */
    'title'      => 'string',

    /** Required — must be is_callable(). Receives no arguments. */
    'callback'   => callable,

    /** Optional — sidebar label (defaults to title). */
    'menu_title' => 'string',

    /** Optional — only meaningful for top-level menus. */
    'icon'       => 'dashicons-shield',

    /** Optional — sort order under gDash; lower = higher. Default: 50. */
    'priority'   => 50,

    /** Optional — required capability. Default: 'manage_options'. */
    'capability' => 'manage_options',

    /** Optional — parent slug. Default: 'gcore-dashboard'. */
    'parent'     => 'gcore-dashboard',
]);
```

### Lifecycle

1. Call `gDash::register_panel(...)` from `plugins_loaded` (priority 20+),
   `init`, or any earlier hook. Anything that runs before WordPress fires
   `admin_menu` (priority 10) is fine.
2. The dashboard collects all registered panels, sorts by `priority` then
   `slug`, and emits `add_submenu_page()` for each panel the current user
   has the declared capability for.
3. Failures (missing required field, non-callable callback, duplicate slug)
   `wp_die()` in `WP_DEBUG` and `error_log()` in production. Your module
   should not crash the dashboard for the operator under any circumstances.

### Capability filtering

`register_panel` accepts a single `capability` string. If you need
multi-cap logic, do that inside the callback — `current_user_can()` checks
short-circuit cleanly there.

### Best practices

- **Namespace your slug**: `myext-overview`, not `overview`. Two modules
  registering the same slug last-write-wins with a logged warning;
  namespacing avoids collisions.
- **Defer heavy work**: keep the registration lightweight (one
  `register_panel` call). Do the expensive page rendering in the callback,
  not at registration time.
- **Defensive load**: gate registration on `class_exists('gDash')`. If
  the dashboard is uninstalled, your module must continue to function
  (just without a UI surface).
- **Pages are read-only-by-default**: don't invent your own write surface;
  write actions are planned via the CLI ↔ GUI parity contract
  (`geodineum` CLI subcommand → POST handler that shells out to the binary).

## ValKey conventions consumed by the dashboard

The dashboard is a *consumer* of the existing pattern set, not an
inventor. Keys it reads:

```
gnode:sites:registry                 (SET; bare — global site registry)
gnode:site:<site_id>:meta            (HASH; bare — global per-site meta)
geodineum:config_schema:_index       (SET; bare — global schema index)
geodineum:config_schema:<component>  (HASH; bare — per-component schema)
{<site_id>}:gnode:health             (STREAM; hash-tagged)
{<site_id>}:metrics:cache            (HASH; hash-tagged) — hits/misses/writes
{<site_id>}:metrics:requests:total   (STRING/INT; hash-tagged)
{<site_id>}:metrics:requests:<svc>   (STRING/INT; hash-tagged)
{<site_id>}:metrics:clients:hll:YYYY-MM-DD (HLL; hash-tagged)
```

All per-site keyspaces are hash-tagged per the cluster-safety invariant.

## Authentication

The dashboard reuses the existing auth stack — it adds **zero** new
primitives:

1. WordPress login + `current_user_can('manage_options')` cap check
2. WordPress nonces on every POST handler
3. gCore SecurityManager (existing)
4. gCore ViewKey gate for non-production sites
5. ValKey ACL: `geodineum_dashboard` user (read-only + narrow audit-log
   write) — instance of the existing per-component ACL onboarding
   pattern

## File layout

```
gCore/Modules/Dashboard/
├── DashboardManager.php   Module shell (ModuleInterface marker)
├── Admin/
│   └── DashboardAdmin.php Singleton + register_panel + page renders
├── examples/
│   └── example-panel.php  Copy-paste skeleton for extension authors
└── README.md              This file

gCore/gcore-mu/assets/
├── three.min.js           Vendored three.js r128 (~600KB)
├── THREE_VERSION.txt      Version-pin documentation
└── dashboard.css          Admin-side CSS (enqueued via wp_enqueue_style)
```

The dashboard is wired into the MU-plugin via the `$adminModules` array
at `gcore-mu/wp-hooks.php` so it self-loads on `is_admin()` requests.
