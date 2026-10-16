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
| Point of sale & online payments — SRS §5.9, FR-POS-001…007, §6.3 | `/pos` (till), `/pos/sales`, `/pos/shifts`; *Pay online* on orders and invoices; public `/pay/return` and `POST /webhooks/paystack/{house}` | **Till**: scan a barcode or type a name/SKU (Enter adds an exact match, F9 pays), tap products (live stock for the shift's branch), attach a customer, take **payments on orders** (deposits/balances) in the same basket. Split tenders (cash, card, transfer, mobile money); change only from cash; quick-cash buttons. Prices are set by the server; changing a price or discounting past the house limit needs *Override price*. Every sale carries a `client_ref`, so a retried or double-clicked sale is saved once (scenario 35); stock posts to the ledger in the same transaction. **Shifts**: open with a float; cash in/out with a reason; close with a **blind count** (cashiers see the expected cash only after closing; supervisors with *Void sale* always see it) — expected = float + cash taken − cash refunds + paid in − paid out, and a difference needs a reason. **Sales**: 80 mm printable receipts, void (while the shift is open; stock back, order payments reversed), part **returns** with refund by cash (from the refunder's drawer), card, transfer, mobile money or store credit, optional restock. **Paystack** (House settings › Online payments): keys stored encrypted; *Pay online* creates a link for an order/invoice balance; payments are confirmed only after verifying with Paystack (webhook signature checked, each event processed once — scenario 36), and `fhmp:verify-payments` (every 15 min) catches any the webhook missed. Set the webhook URL shown in settings in your Paystack dashboard. Demo stock for the till comes from `DemoInventorySeeder` (run after `DemoCatalogueSeeder`). |
| Production, fittings, quality & delivery — SRS §5.9, §6.2 | `/production` (board, *My work*), `/production/tasks/{id}`, `/fittings`, `/production/setup` (workflows & checklists), order › Production / Fittings panels, `/deliveries/{id}` | **Workflows** (Production › Workflows & checks): ordered stages with kind (work, fitting, quality check), hours (8 = one day), usual role, *waits for earlier stages*, customer visibility, standing instructions and a checklist for QC stages. A workflow applies to line kinds and optionally one style category; the most specific wins. Starter workflows (*Bespoke garment*: cutting → sewing → fitting → finishing → quality check; *Alteration / service*) and a *Final garment check* come with every new house (`php artisan fhmp:production-kit` for existing ones). **Planning** an order copies the stages into tasks per garment (editing a workflow later never changes planned work), with due dates worked back from the promise date over the house's working days. **Tasks**: start / hold / done / reopen, assignment (tailors work on their own or unassigned tasks; managers on any), priority, notes with photo evidence; every change is an append-only production event. Garment and order status follow the tasks (in production → fitting → ready) through the normal order transitions; an order can't be marked ready by hand while work is open. **Material** is issued from a task and tied to that garment. **Fittings**: book against a garment, reminders 24h and 2h before (configurable), moving or cancelling one cancels its old reminders (scenario 40); record attended / no-show and approved or alterations. **Alterations & rework** have reason, responsible party, cost treatment (a chargeable one is billed with an invoice line) and hold the fitting or check that found them until done. **Quality checks** use the stage's checklist; a fail creates rework for whoever last worked on the garment and notifies managers. **Handover** only for finished garments, by collection or delivery, with recipient, confirmation (signature, ID, code, photo) and, if the house allows release with a balance, the written terms. **Late-delivery risk** (`fhmp:delivery-risk` daily 07:15, and after each update): plain-language reasons (date passed, work left vs working days, overdue or unassigned tasks, overloaded people); managers and the order owner are told only when risk rises. Settings: working days, fitting reminder hours, open-task limit per person, release with balance. |

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
| GET  | `/api/v1/pos/shifts/current` | your open shift (or `null`) with today's takings |
| POST | `/api/v1/pos/shifts/open` · `/api/v1/pos/shifts/{id}/close` | `opening_float`, optional `branch` (code) / `counted_cash`, `variance_reason` |
| POST | `/api/v1/pos/sales` · GET `/api/v1/pos/sales/{id}` | `client_ref` (generate once per basket and reuse on retry — a replay returns the same sale with `replayed:true`), optional `customer`, lines `{"sku":"…","quantity":1}` or `{"order":"ORD-…","amount":25000}`, tenders `{"method":"cash","amount":20000}` |
| GET  | `/api/v1/production/tasks` · GET `/api/v1/production/tasks/{id}` | `who=mine` (default for tailors) / `all` / `unassigned`, `status`, `order`; detail includes history, checklist (QC) and what it is waiting on |
| POST | `/api/v1/production/tasks/{id}/events` | `type` start / hold / complete / note, `note`, optional `evidence` image (multipart) |
| POST | `/api/v1/fittings` · `/api/v1/fittings/{id}/record` | book: `order` (or `customer`), `line`, `scheduled_at` (house time); record: `status` attended / no_show, `outcome` approved / alterations, `alterations[]` |
| POST | `/api/v1/quality-checks` · `/api/v1/alteration-tasks` · `/api/v1/deliveries` | QC: `task`, `answers[]` `{passed, note}`; alteration: `order`, `line`, `name`, `reason`, `responsible_party`, `cost_treatment`; handover: `order`, `lines[]`, `method`, `recipient_name`, `confirmation` |

Errors: `{ success:false, code, message, field_errors?, correlation_id }`. Every response
carries `X-Request-Id`.

## What was removed

All school-management code (students, classes, results, exams, CBT, timetable, LMS,
payroll, accounting, website, parent portal…). It is still on the `main` branch.
