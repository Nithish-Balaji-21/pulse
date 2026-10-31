# Monitoring deployment notes

The monitoring integration is prepared locally. Do not treat a local preview as a production deployment sign-off.

## Source and local application

The module now lives in `D:\IMIK\pulse`, alongside the existing superadmin modules. The older `pulse-queue-monitor` worktree is retained as a backup/reference and is not required by this application.

Start the main application using `D:\IMIK\start-pulse.bat` and open `http://localhost:8000/dashboard`. Choose **Admin → DevOps Tools**, or use the button in **Platform settings**. All guide templates, images, dashboard overrides, configuration and access middleware are included in this repository. Local runtime dependencies and the SQLite monitor store live in the main Ubuntu runtime (`/home/preethi/pulse-local`); the runtime is a copy of the source, not another source project.

## Included pages

Superadmin Settings and the Superadmin menu link to `/{team}/monitoring`.
The hub opens `/queue-monitor` and `/monitor/guide`.
Only active, authenticated superadmins may access monitoring data. Responses use private, no-store caching.
The guide source in `resources/markdown/monitoring-guide.md` must accompany the templates.

## Deployment

1. Re-run the locked dependency audit and application regression tests before release. The local PHP audit was clean after the four dependency updates; this is a point-in-time check.
2. Install the reviewed lockfile with production dependencies, and build the application's production CSS/assets with Node 20 or newer (required by the locked Tailwind tooling). Use the normal HTTPS web server, not `artisan serve`.
3. Set `APP_ENV=production`, `APP_DEBUG=false`, and `DEBUGBAR_ENABLED=false`. Keep payload storage and the optional REST API disabled unless separately reviewed. Do not deploy the local test database or test accounts.
4. Choose the monitor database explicitly. The local SQLite file is a trial store; a multi-worker production installation needs a shared, backed-up database connection with the package migrations applied. Review pending migrations before execution. Never run demo seeders against production.
5. Keep `QUEUE_MONITOR_REFRESH_INTERVAL=15000` (milliseconds). Retention is seven days with a 500,000-row ceiling for prunable records; pending/processing rows need separate operational investigation.
6. Ensure the Laravel scheduler is running so `queue-monitor:prune` executes at 03:30. On multiple hosts, appoint a scheduler owner or use a reviewed shared-lock strategy.
7. Restart intended queue workers through the existing process manager after installing the collector. Do not add duplicate consumers or change production queue routing for the dashboard.
8. Verify one normal job reaches the monitor; verify a non-superadmin is denied. Confirm the guide and assets render with production caching enabled. Validate the actual Nightwatch/heartbeat settings independently.

## Validation and rollback

Local validation on 2026-09-28: 1,157 application tests passed, 13 skipped (3,185 assertions), using the isolated MySQL test database. The 13 focused monitoring tests passed, Blade templates compiled, the Vite production build passed, and the PHP production dependency audit reported no advisories. Browser previews of the hub, dashboard and guide were checked. Production services and external integrations still require the deployment checks above.

Run the focused monitoring access tests and the full application suite on an isolated test database. Never use a production database for test migrations.

If collection causes issues, set `QUEUE_MONITOR_ENABLED=false`, rebuild configuration cache and restart workers using the existing deployment procedure. Disable the UI with `QUEUE_MONITOR_UI_ENABLED=false` if needed. Preserve monitor data for investigation; do not drop tables during rollback.

The local demo account and passwords belong only to the local database. No credentials are part of this deployment guide.
