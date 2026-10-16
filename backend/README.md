# FatihaAllOver API foundation

Laravel 13 modular monolith, Eloquent, PostgreSQL, and Sanctum. The architectural baseline remains [the blueprint](../documentation/ARCHITECTURE_BLUEPRINT.md). Customer/admin Next.js apps and mobile apps are not initialized in this milestone.

## Runtime and local startup

This project's dependency lock is resolved for PHP 8.4 or newer (Laravel 13 itself requires at least PHP 8.3). Use PHP with `pdo_pgsql` and the normal Laravel extensions. On this Windows machine the default `php` is 8.2; PHP 8.4 is already installed. Select it for the current PowerShell session without changing system settings:

```powershell
$env:Path = 'C:\php\8.4;' + $env:Path
Set-Location 'C:\Users\Dell\Projects\FatihaAllOver-Ecommerce\backend'
php -v
composer check-platform-reqs
php artisan serve --host=127.0.0.1 --port=8000
```

On a fresh clone, first run `composer install`, copy `.env.example` to `.env` only if no local `.env` exists, then run `php artisan key:generate`. Do not regenerate the key of an existing deployment. No Composer setup script runs migrations, database seeders, or a frontend build.

Development installs use normal PSR-4 autoloading to avoid an expensive optimized class scan on this Windows filesystem. The later VPS deployment should explicitly use `composer install --no-dev --optimize-autoloader` and the production validation/runbook; this milestone does not deploy the application.

- `GET /up`: Laravel application liveness.
- `GET /api/v1/health`: JSON API liveness; it does **not** assert database connectivity.
- `/api/v1/*`: reserved versioned API routes; unknown paths return JSON errors.
- `GET /sanctum/csrf-cookie`: framework CSRF bootstrap for future first-party SPA login.
- No registration, login, product, pricing, order, or other business endpoints exist yet.

## Database setup is pending

The driver is `pgsql`; there is no SQLite fallback. No PostgreSQL server/client or database credentials were found during setup, and no database or tables were created. Local `.env` and its safe example leave `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` blank intentionally. `127.0.0.1:5432` is only a default local address, not a verified connection.

Before the database milestone, provision PostgreSQL locally or obtain a managed development database. Set the actual `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and provider-required `DB_SSLMODE` in the ignored `.env`. Do not send passwords in chat or commit them. After configuring real credentials, verify without changing schema:

```powershell
php artisan config:clear
php artisan db:show --database=pgsql
```

Do not run migrations or seeders yet. The generated default users/password-reset/sessions migration and sample user factory were removed so the approved identity schema can be designed in its own milestone. The stock Laravel User authentication adapter is retained only for framework/Sanctum integration; no customer roles, wholesale type, or business behavior has been added. The remaining cache/jobs and Sanctum token migrations are framework infrastructure, are unexecuted, and must be reviewed alongside the identity/session schema before migration.

Local sessions/cache use files and queues use `sync` so liveness and CSRF bootstrap can work without a database. These are temporary local foundation defaults. Production retains the blueprint's PostgreSQL-backed sessions/cache/queues after their schemas are implemented, plus supervised workers and the scheduler. No database-dependent authentication has been claimed as verified.

## Sanctum and browser clients

Sanctum is installed via Laravel's `install:api` command, with migration execution disabled. Stateful API middleware supports cookie/session authentication; the stock User adapter has `HasApiTokens` for future mobile tokens. No tokens are issued and no authentication UI is included. Token lifetime defaults to 43,200 minutes (30 days), configurable through `SANCTUM_EXPIRATION`; mobile issuance/revocation and pruning will be implemented with authentication.

The local defaults allow customer port 3000 and admin port 3001 on `localhost` or `127.0.0.1`. Use the same hostname consistently for frontend and API; these two hostnames do not share cookies. Browser requests must send credentials and the XSRF header obtained from `/sanctum/csrf-cookie`. CORS allows only the listed origins, including credentialed preflight; it is not authorization.

Before staging/production, configure the approved domains explicitly:

- `APP_URL`: HTTPS API URL; `APP_ENV=production` and `APP_DEBUG=false` for production.
- `CORS_ALLOWED_ORIGINS`: comma-separated full frontend origins including `https://`; never wildcard with credentials.
- `SANCTUM_STATEFUL_DOMAINS`: trusted frontend hostnames, with ports where needed, without scheme.
- `SESSION_DOMAIN`: shared controlled parent domain for the two SPAs/API.
- `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, and HTTPS throughout.
- Database-backed session/cache/queue settings only after reviewed migrations are available.

Browser authentication uses sessions; future mobile calls use Bearer tokens stored securely on the device. Ownership, staff permissions, MFA, registration, login, and device-token endpoints remain deferred. Shared-parent-domain requirements and isolated staging credentials still apply.

## Verification and scope

```powershell
composer validate --strict
composer check-platform-reqs
php artisan about --only=environment
php artisan route:list
php artisan test
```

Foundation tests do not connect to PostgreSQL. They verify liveness, the versioned route boundary, JSON errors, credentialed CORS allowlisting, CSRF bootstrap cookies, and rejection of forged browser requests using a test-only route. No test requires production credentials or applies migrations.

No domain folders/models/controllers/CRUD are created ahead of their milestones. `routes/api.php`, `bootstrap/app.php`, configuration and service providers form the extension points for the modular monolith. Cloudinary integration, business schema, and all storefront/admin/mobile work remain deferred. `.env`, generated keys, vendor files, runtime caches and logs are ignored; commit `.env.example` and `composer.lock` with reviewed foundation source.
