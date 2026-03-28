# Customer / Tenancy Foundation

## What a Customer Is

A `customer` is the top-level business tenant in Procynia.

- One customer has many users
- One customer has many departments
- One customer has many watch profiles
- Customer-facing workflow data must be scoped to one customer

The first concrete customer setup is Norwegian-first:

- `customers.nationality_id -> nationalities.code = NO`
- `customers.language_id -> languages.code = no`

## Canonical Language Resolution Order

The application resolves display language in exactly this order:

1. `users.preferred_language_id -> languages.code`
2. `users.nationality_id -> nationalities.code` derived rule
3. `customers.language_id -> languages.code`
4. system fallback: `no`

Current nationality mapping:

- `NO -> no`

The canonical implementation lives in [app/Support/CustomerContext.php](/Applications/XAMPP/xamppfiles/htdocs/procynia/app/Support/CustomerContext.php).

The admin locale is set through [app/Http/Middleware/SetCustomerLocale.php](/Applications/XAMPP/xamppfiles/htdocs/procynia/app/Http/Middleware/SetCustomerLocale.php).

## Norwegian-First Behavior

For Norwegian users/customers:

- CPV descriptions are shown in Norwegian only
- customer-facing labels should use the Laravel translation path under `lang/no/procynia.php`
- mixed English/Norwegian CPV rendering is no longer used in customer-facing admin views

## Table Ownership Classification

### Category A — Customer-Owned

These tables carry explicit `customer_id` ownership:

- `users`
- `departments`
- `watch_profiles`
- `notice_decisions`
- `notice_attentions`

Interpretation:

- `users.customer_id` may be `NULL` only for internal admins/system users
- all active customer-facing business rows must belong to a customer

### Category B — Global Shared Reference / Intake Data

These tables stay global:

- `customers`
- `notices`
- `notice_raw_xml`
- `notice_cpv_codes`
- `notice_lots`
- `cpv_codes`
- `watch_profile_cpv_codes`
- `sync_logs`
- `doffin_import_runs`

Reason:

- Doffin import is a shared upstream intake pipeline
- CPV catalog is a shared lookup
- system logs/import runs are operational, not customer-owned business objects

### Category C — Hybrid / Explicit Interpretation Required

These are stored on global notices but interpreted through customer-owned routing/workflow overlays:

- `notices.department_scores`
- `notices.visible_to_departments`
- `notices.score_breakdown`

Legacy hybrid workflow fields still present on `notices`:

- `internal_status`
- `internal_comment`
- `assigned_to_user_id`
- `status_changed_at`
- `status_changed_by_user_id`
- `decision_by_user_id`

Current interpretation:

- the notice master row remains global
- customer-owned workflow history and unread queue live in `notice_decisions` and `notice_attentions`
- the embedded notice workflow fields remain legacy/shared state from the internal-admin phase

This is acceptable for the current baseline because all live customer-facing data is backfilled into one initial customer. If multiple customers need independent workflow state on the same notice concurrently, these legacy workflow fields must be extracted into a customer-scoped overlay model.

## Notice Ownership Decision

Procynia uses **Option A — Global Notices, Customer-Specific Visibility**.

- Doffin notices are imported once into a shared master dataset
- watch profiles score/rout notices into departments
- departments belong to customers
- customers only see notices routed to their own departments
- customer-facing workflow data is stored in customer-owned overlay tables where available

This avoids duplicating Doffin notices per customer while keeping routing customer-aware.

## Rules Preventing Cross-Customer Leakage

### Query Scoping

Customer-facing Filament resources use server-side `customer_id` scoping through [app/Support/CustomerContext.php](/Applications/XAMPP/xamppfiles/htdocs/procynia/app/Support/CustomerContext.php):

- `DepartmentResource`
- `WatchProfileResource`
- `NoticeAttentionResource`
- `NoticeResource` visibility filtering via routed department ids

Internal-only resources remain accessible only to internal admins:

- `CustomerResource`
- `SyncLogResource`
- `DoffinImportRunResource`

### Relationship Selectors

Customer-facing selectors are restricted to the current customer:

- department selection on watch profiles
- customer-owned department forms
- notice assignment user selection
- notice-attention assignment filters

### Routing Safety

Department routing only evaluates watch profiles where:

- `watch_profiles.customer_id = departments.customer_id`

This prevents a watch profile from one customer from routing a notice into another customer’s department.

### Workflow / Attention Safety

New workflow and attention rows store `customer_id`:

- `notice_decisions.customer_id`
- `notice_attentions.customer_id`

## Initial Backfill Baseline

The transition creates one initial tenant:

- `customers.slug = default-customer`
- `customers.nationality_id -> NO`
- `customers.language_id -> no`

Backfill rules:

- departments -> assigned to `default-customer`
- watch profiles -> assigned to `default-customer`
- customer-facing users -> assigned to `default-customer`
- system/test users with `@example.com` email remain internal (`customer_id = NULL`)
- notice decisions -> backfilled from related department/user ownership
- notice attentions -> backfilled from related department ownership

## Internal Admin Rule

Current explicit rule:

- `users.role = super_admin` and `users.customer_id IS NULL` means internal admin/system user
- only internal admins may access customer-level operational resources like `CustomerResource`, `SyncLogResource`, and `DoffinImportRunResource`
- `super_admin` may also enter the customer frontend at `/app` for verification and support
- without an explicit customer context, `super_admin` sees the frontend shell with an empty state only
- notice detail and document downloads still require a resolvable customer context and do not expose customer data to customer-less internal admins

## User Role Model

The system uses one canonical role field on `users.role` with exactly these values:

- `super_admin`
- `customer_admin`
- `user`

Semantics:

- `super_admin`: internal system-wide administrator
- `customer_admin`: administrator for one customer only
- `user`: regular customer user

Validation rules:

- `super_admin` must remain an internal user with `customer_id = NULL`
- `customer_admin` must have `customer_id`
- `user` must have `customer_id`

## Central User Administration

The central user management surface is [UserResource](\/Applications\/XAMPP\/xamppfiles\/htdocs\/procynia\/app\/Filament\/Resources\/UserResource.php).

Access rules:

- `super_admin` can manage all users
- `customer_admin` can manage only users in their own customer
- `user` cannot access the User Resource

Chosen customer-admin model:

- **Option A**
- a `customer_admin` may manage both regular users and other `customer_admin` users inside the same customer
- a `customer_admin` may never create or promote a `super_admin`

Offboarding rule:

- Procynia uses `users.is_active` as the canonical lifecycle control
- user deletion is not the normal offboarding path
- inactive users cannot access the Filament panel

Language / nationality maintenance:

- `users.nationality_id` and `users.preferred_language_id` are maintained through the User Resource
- these values feed the canonical language resolution order described above

## Onboarding Flow for a New Customer

Current deterministic onboarding flow:

1. Internal admin creates the customer in `Customers`
2. Set `nationality_id`
3. Set `language_id`
4. Create customer departments
5. Create customer watch profiles and attach them to the customer’s departments
6. Create customer user accounts and bind them to `customer_id`
7. Optionally set `preferred_language_id` or `nationality_id` on individual users
8. Re-run the existing Doffin processing flow so routing/attentions materialize for the new tenant

## Reference Tables

The language/nationality model now uses authoritative reference tables only:

- `nationalities`
  - ISO 3166-1 alpha-2 `code`
  - `name_en`
  - `name_no`
  - `flag_emoji`
- `languages`
  - ISO 639-1 `code`
  - `name_en`
  - `name_no`

These are the canonical source for customer and user language/nationality state.

## CPV Catalog Model

- `notice_cpv_codes` is a relation table only: one row per `notice + cpv_code` present on that notice
- `cpv_codes` is the master catalog
- `cpv_codes.description_en` and `cpv_codes.description_no` are both required
- UI resolves CPV readability through the master catalog only
