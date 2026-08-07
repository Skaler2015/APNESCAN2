# ApneScan Enterprise Admin

Modular, MVC-style PHP admin dashboard for ApneScan usage analytics. The single
public entry point is `../admin.php` (front controller); everything else is
included from disk and blocked from direct web access via `.htaccess`.

## Structure

```
admin.php                     Front controller: bootstrap → auth gate → route → shell
admin/
  includes/
    init.php                  Session hardening, config, PDO, schema, CSRF, roles
    helpers.php               Escaping, formatting, DB shortcuts, auth, audit, rate-limit
    metrics.php               All analytics queries as reusable functions
    ui.php                    Presentation helpers: icons, KPI, bars, Chart.js, tables
    actions.php               Central POST dispatcher (CSRF + capability + audit, PRG)
  components/
    head.php                  <head> + design system (glassmorphism theme, light/dark)
    sidebar.php               Collapsible navigation
    topbar.php                Search, notifications, profile, theme, live, breadcrumb
  auth/
    setup.php                 First-run wizard (writes ../api/config.php)
    login.php                 Rate-limited, session-regenerating login
  pages/                      One view per route (dashboard, analytics, live, scanner,
                              ocr, events, install, reports, versions, devices, os,
                              users, notifications, backup, export, audit, health,
                              settings, help) + export_stream.php (downloads)
  api/
    api.php                   Read-only JSON API (session- or key-authenticated)
```

## Security

CSRF tokens on every form · prepared statements everywhere · login rate-limiting
(8 / 10 min) · session id regeneration on login · HttpOnly + SameSite cookies ·
role-based permissions (super_admin / admin / manager / operator / viewer) ·
full audit log. Config credentials live in `../api/config.php`, denied to the web.

## Roles → capabilities

| Role        | Capabilities                                                    |
|-------------|-----------------------------------------------------------------|
| super_admin | everything                                                      |
| admin       | view, export, controls, reports, notifications, backup, settings, audit, users |
| manager     | view, export, reports, notifications                            |
| operator    | view, controls                                                  |
| viewer      | view                                                            |

The bootstrap `admin` user (password in `api/config.php`) is always super_admin.
Additional admins live in the `admin_users` table (Users & Roles page).

## Data source

Populated by the ApneScan desktop app via `../api/track.php` (events, live
presence, geo), `../api/feedback.php` (feedback) and — from Phase 2 — a device
profile endpoint. No document content or personal data is ever collected.

## Phased roadmap

- **Phase 1 (done):** architecture, shell, all pages, charts, security, REST API, live.
- **Phase 2 (done):** app-side collection of device specs, scanner model, DPI/color,
  OCR timing/language, scan time and PDF sizes — the Devices/Scanner/OCR panels fill in.
- **Phase 3 (done):** file-cache layer + retention auto-apply, SMTP email (pure-PHP
  mailer) with test send, TOTP two-factor auth, scheduled + on-server backups,
  and API-key generation. See `includes/cache.php`, `totp.php`, `backup.php`,
  `../api/mailer.php`, `../api/backup-cron.php`.
- **Phase 4 (done):** global cross-entity search (`pages/search.php`), advanced
  date filters (presets + custom range) on Events, dedicated Feature Analytics
  page, Chart.js export-PNG + wheel-zoom/pan + monthly trend, and Slack/Discord/
  generic webhook alerts (throttled) with a test button.
