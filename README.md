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

## Modules built

| Module | Screens | Notes |
|--------|---------|-------|
| Customers (CRM) — SRS §5.3 | `/customers`, `/follow-ups` | Auto numbers (`CUS-00001`, format in House Settings), tags, VIP, style preferences, consent per channel/purpose, notes (sensitive notes need *View customer sensitive notes*), follow-ups, duplicate warning (phone/e-mail/name), merge, timeline, CSV export. Try it with `php artisan db:seed --class=DemoCustomersSeeder`. |
| Measurements — SRS §5.4/§5.5/§6.1/§6.5 | `/measurements`, `/measurement-templates`, `/measurement-parameters`, customer › Measurements tab, public `/m/{token}` | Starter kit (21 parameters, 5 published templates; `php artisan fhmp:measurement-kit` for existing houses). Values stored in **millimetres**, entered in inches or cm (½ and `38 1/2` accepted). Versioned templates (published versions are frozen; sessions keep their version). Draft → submitted → approved/sent back; approved sessions are read-only and corrected by a superseding session. Changes vs the last approved values are flagged (parameter threshold, else house setting, default 5%) and raise one review follow-up. Secure customer links: hashed 48-char token, expiry, revoke, 10-attempt lock, never show previous values, submissions wait for approval. Daily `fhmp:measurement-reviews` (07:00) reminds staff when approved measurements are older than the house's review interval (default 6 months) — requires the Laravel scheduler (`php artisan schedule:work` locally). |
| Styles & ready-made catalogue — SRS FR-MEA-006, FR-POS-007 | `/styles`, `/products`, `/catalogue/categories` | **Styles** (bespoke designs, `STY-00001`): category, audience, description, workroom technical notes, up to 12 pictures (first = cover), starting price + optional add-ons, estimated cost (only with *View costing*), lead time, typical fabric, and the measurement templates it needs. Every change is made on a draft; *Publish style* freezes it as a new version and pins the template versions live at that moment, so past orders stay reproducible. **Products** (ready-made, `PRD-00001`): one row per size/colour with price, cost, SKU (automatic if blank), barcode (scan, type or generate EAN-13), reorder level; optional link to the style it was made from. Stock quantities arrive with the Inventory module. Starter categories are created for every new house; demo data: `php artisan db:seed --class=DemoCatalogueSeeder`. Pictures are stored on the `public` disk, so run `php artisan storage:link` once. |
| Inventory & purchasing — SRS §5.6 | `/inventory`, `/purchase-orders`, `/goods-receipts`, `/stock-transfers`, `/stock-adjustments`, `/material-issues`, `/suppliers` | Stock items (fabric, trims, consumables, equipment, ready-made variants — created automatically for stock-tracked products — and **customer-owned material**, kept out of stock value). Locations are your branches. Fractional quantities with per-item precision; rolls / dye lots and **remnants**. Every change is a line in the **append-only stock ledger** (`stock_movements`); balances are a cache checked nightly by `php artisan fhmp:stock-reconcile` (`--fix` rebuilds them). Weighted-average costing. Purchase orders (draft → approval → part/fully received), direct receipts, transfers, reservations for jobs, material issues to production (uses the customer's reservation first, won't eat other jobs' reserved stock) and returns as remnants, adjustments / wastage / stock counts (only differences post) / opening balances. **Approval limits** in House settings: above them a second person must approve, unless nobody else in the house can (then self-approval is allowed and noted in the audit log). Low-stock alerts once per item and location until it recovers. Demo data: `php artisan db:seed --class=DemoInventorySeeder`. |
| Quotes, orders, invoices & payments — SRS §5.8 | `/quotations`, `/orders`, `/invoices`, `/payments`, customer › Orders tab | **Quotations** (sent → accepted becomes a confirmed order). **Orders** with garment lines: made-to-measure styles (version pinned, add-ons priced), ready-made items, services and charges; per-garment measurements, assignee and wearer; status flow draft → confirmed → in production ⇄ fitting → ready → delivered → closed (stage names can be renamed per house, `order_status_labels` setting), cancel/reopen with “Cancel order”. Changes after confirmation need a reason and are kept as **revisions**. **Materials** reserved per order (released on cancel), issued to the workroom from the order. All totals are calculated on the server in whole kobo; prices below the catalogue and discounts above the house limit need “Override price”. **Money**: invoices (deposit / balance / full / stand-alone; frozen once issued, voided not edited), payments with bank reference duplicate check, applied to invoices then orders, the rest kept as **customer credit**; refunds (never more than refundable, need approval) and reversals (bounced payments). Balances are derived from allocations — nothing is ever edited or deleted. Settings: usual deposit %, discount limit, refund approval limit, quotation validity. Demo data: `php artisan db:seed --class=DemoOrdersSeeder`. |

## API v1

| Method | Endpoint | Notes |
|--------|----------|-------|
| POST | `/api/v1/auth/login` | `login` (email/username/phone), `password`, `device_name` → Bearer token + houses |
| GET  | `/api/v1/me` | user, houses, current house, roles, permissions |
| POST | `/api/v1/auth/logout` | revokes the current token |
| POST | `/api/v1/auth/password` | change password (revokes other devices) |
| GET  | `/api/v1/tenants` | houses the user can work in |
| GET  | `/api/v1/tenants/current` | settings + branches of the current house (`X-Tenant` optional) |
| GET/POST | `/api/v1/customers` | search (`q`) / create — 409 `possible_duplicate` unless `confirm_duplicate=true`; honours `Idempotency-Key` |
| GET/PATCH | `/api/v1/customers/{id}` | `{id}` = customer public id; PATCH is a partial update |
| GET  | `/api/v1/customers/{id}/timeline` | notes, follow-ups and changes |
| POST | `/api/v1/customers/{id}/follow-ups` | create a follow-up (`Idempotency-Key` supported) |
| GET  | `/api/v1/measurement-templates` | usable templates with their current version's parameters |
| GET/POST | `/api/v1/customers/{id}/measurement-sessions` | list / create. Body: `template_version_id`, `unit`, `values` keyed by code (`{"waist":"32","thigh":{"left":"22","right":"22 1/4"}}`), optional `submit=true` |
| GET/PATCH | `/api/v1/measurement-sessions/{id}` | `?unit=cm` for display; PATCH only for draft/sent-back (409 otherwise), only sent codes change |
| POST | `/api/v1/measurement-sessions/{id}/submit` · `/approve` · `/reject` (`reason`) | review workflow |
| GET  | `/api/v1/measurement-sessions/{id}/comparison` | per-value change vs last approved (`delta_mm`, `delta`, `delta_pct`, `flagged`) |
| POST | `/api/v1/customers/{id}/measurement-requests` · `/api/v1/measurement-requests/{id}/revoke` | create a customer link (URL returned once) / revoke it |
| GET/POST | `/api/v1/styles` | list (`q`, `category_id`, `status`) / create a style with its first draft |
| GET/PATCH | `/api/v1/styles/{id}` | live version + draft; PATCH edits the draft (`description`, `technical_notes`, `base_price`, `price_options[]`, `lead_time_days`, `fabric_*`, `template_ids[]`…) |
| POST | `/api/v1/styles/{id}/versions` · `/api/v1/styles/{id}/images` | start a new draft from the live version / upload pictures (multipart `images[]`) |
| POST | `/api/v1/style-versions/{id}/publish` | needs *Publish style*; freezes the version (`change_notes` optional) |
| GET  | `/api/v1/products` · `/api/v1/products/{id}` · `/api/v1/products/lookup?code=` | ready-made products and variants; lookup matches barcode, SKU or product code (for scanning) |
| GET  | `/api/v1/catalogue` | what customers may see: published styles and products switched on for the catalogue, never costs |
| GET/POST | `/api/v1/inventory-items` · GET `/api/v1/inventory-items/{id}` | `{id}` may be the public id, code or barcode; detail includes availability (on hand, reserved, free, on order) per location and lot |
| GET  | `/api/v1/stock-balances` · `/api/v1/stock-movements` | filter by `branch_id`, `item` |
| POST | `/api/v1/stock-transfers` · `/api/v1/stock-counts` · `/api/v1/material-issues` | lines use `item` (public id / code / barcode), optional `lot`, `quantity` or `counted_quantity`; send an `Idempotency-Key` so retries never post twice |
| GET  | `/api/v1/suppliers` · GET/POST `/api/v1/purchase-orders` · GET `/api/v1/purchase-orders/{id}` | create with `submit=true` to send for approval |
| POST | `/api/v1/purchase-orders/{id}/submit` · `/approve` · POST `/api/v1/goods-receipts` | receive against a PO (`purchase_order_id` + `purchase_order_line_id`) or directly (`branch_id` + `item`) |
| GET/POST | `/api/v1/quotations` · POST `/api/v1/quotations/{id}/accept` | lines: `{"kind":"bespoke","style":"STY-00001","options":["Hand beading"]}`, `{"kind":"ready_made","sku":"…"}`, `{"kind":"service","description":"…","unit_price":3000}`; `send=true` marks it sent |
| GET/POST | `/api/v1/orders` · GET/PATCH `/api/v1/orders/{id}` | `status=open` filter; `confirm=true` on create; PATCH a confirmed order needs `revision_reason` |
| POST | `/api/v1/orders/{id}/status-events` · `/api/v1/orders/{id}/cancel` | move along the status flow / cancel with `reason` |
| POST | `/api/v1/invoices` · `/api/v1/invoices/{id}/issue` | `order_id`, `kind` deposit / balance / full, optional `amount`, `issue=true` |
| POST | `/api/v1/payments` · `/api/v1/payments/{id}/allocate` · `/api/v1/payments/{id}/refund` | send an `Idempotency-Key`; apply to `order_id` / `invoice_id` or `auto_apply`; refunds wait for approval |

Errors: `{ success:false, code, message, field_errors?, correlation_id }`. Every response
carries `X-Request-Id`.

## What was removed

All school-management code (students, classes, results, exams, CBT, timetable, LMS,
payroll, accounting, website, parent portal…). It is still on the `main` branch.
