# Fashion House Management Platform (FHMP)

Laravel 12 · Blade (Velzon theme) · MySQL · Spatie Permission (teams) · Sanctum API

This is the **platform foundation** (SRS roadmap phase 1): multi-tenant SaaS core,
users, roles and permissions, branches, settings, audit trail, and the `/api/v1`
base for the Flutter staff and customer apps. Business modules (CRM, measurements,
inventory, orders, POS, production, projects) are built on top of it.

## Setup (XAMPP)

```bash
composer update laravel/sanctum     # sanctum was in composer.json but not in composer.lock
composer install                   # also installs qrcode/barcode packages missing from vendor/
cp .env.example .env               # or edit your existing .env (see below)
php artisan key:generate           # only for a brand-new .env
# create an EMPTY MySQL database, e.g. fashion_house, and put it in DB_DATABASE
php artisan migrate --seed
php artisan storage:link
```

Sign in with `FHMP_ADMIN_EMAIL` / `FHMP_ADMIN_PASSWORD` (default
`admin@fashionhouse.test` / `password`) and change the password.

Run the tests: `php artisan test` (uses in-memory SQLite).

## Tenancy

| `.env`                     | Meaning |
|----------------------------|---------|
| `TENANCY_MODE=multi`       | SaaS: many fashion houses, house switcher, platform "Fashion Houses" screen, self sign-up (`TENANCY_SELF_SIGNUP`). |
| `TENANCY_MODE=single`      | One fashion house per installation (`TENANCY_DEFAULT_TENANT` slug). Same schema, so it can become SaaS later without migrations. |

How it works:

- `tenants` → `branches`, `tenant_memberships` (user ↔ house, status, home branch, owner flag).
- **Every request runs inside one tenant**, resolved from the user's *active membership*
  (`App\Http\Middleware\ResolveTenant` for web, `ResolveApiTenant` for the API with the
  optional `X-Tenant` header). Client-supplied tenant ids are never trusted (SRS FR-TEN-003).
- The tenant is resolved **before route-model binding**, so `/things/{thing}` can't load
  another house's record.
- Roles are per tenant (Spatie *teams*, `tenant_id`); permissions are global and listed in
  `App\Support\PermissionCatalog` with the default roles from SRS §9.
- `CurrentTenant::id()`, `CurrentTenant::get()`, `CurrentTenant::run($tenant, fn () => …)`.

### Adding a business module (pattern)

1. Migration: `tenant_id` foreign key + composite uniques, e.g. `unique(['tenant_id', 'customer_number'])`;
   money as integer minor units + `currency_code`.
2. Model: `use App\Models\Concerns\BelongsToTenant;` — reads are scoped, `tenant_id` is filled
   on create, and records can't move between tenants.
3. Permissions: add them to `PermissionCatalog::GROUPS` (+ default roles), then
   `php artisan fhmp:sync-permissions`.
4. Controller: `$this->middleware('permission:View customer')`, audit privileged actions with
   `AuditLogger::log('customer.merged', $model, $before, $after)`.
5. Routes: `routes/web.php` (and `routes/api.php` under the `api.tenant` group);
   menu item in `resources/views/layouts/partials/sidebar-menu.blade.php` (replace the "Soon" entry).
6. Tests: copy `tests/Feature/TenantIsolationTest.php` for the new resource.

## API v1

| Method | Endpoint | Notes |
|--------|----------|-------|
| POST | `/api/v1/auth/login` | `login` (email/username/phone), `password`, `device_name` → Bearer token + houses |
| GET  | `/api/v1/me` | user, houses, current house, roles, permissions |
| POST | `/api/v1/auth/logout` | revokes the current token |
| POST | `/api/v1/auth/password` | change password (revokes other devices) |
| GET  | `/api/v1/tenants` | houses the user can work in |
| GET  | `/api/v1/tenants/current` | settings + branches of the current house (`X-Tenant` optional) |

Errors: `{ success:false, code, message, field_errors?, correlation_id }`. Every response
carries `X-Request-Id`.

## What was removed

All school-management code (students, classes, results, exams, CBT, timetable, LMS,
payroll, accounting, website, parent portal…). It is still on the `main` branch.
