# Customer Frontend

## Stack

The customer-facing web application uses:

- Laravel
- Inertia
- React
- Tailwind

Filament remains admin-only.

## Route Structure

Customer routes live under `/app`:

- `/app/notices`
- `/app/notices/{notice}`
- `/app/notices/{notice}/documents/{document}/download`
- `/app/notices/{notice}/documents/download-all`

The login entrypoint is `/login`.

## Notice Visibility Source Of Truth

The first customer notice list is driven by `notice_attentions`.

This means:

- a notice appears in the customer frontend only if there is at least one `notice_attentions` row for the authenticated user's `customer_id`
- list and detail access are both enforced server-side with the same customer-safe rule

## Documents

Document metadata is stored in `notice_documents`.

The catalog is synced from stored Doffin raw XML using:

- `cac:CallForTendersDocumentReference`
- `cac:Attachment`
- `cac:ExternalReference`
- `cbc:URI`

Single-file download:

- validates customer access to the parent notice
- fetches the upstream file
- returns one download response

Download-all:

- validates customer access to the parent notice
- fetches all files
- packages them into one ZIP
- fails the whole ZIP request if one file cannot be fetched

## Tenant Safety

Customer frontend access requires:

- authenticated user
- active user
- role `super_admin`, `customer_admin`, or `user`

All customer-facing notice and document endpoints enforce tenant checks server-side.

The Inertia payload excludes internal admin/debug data and only includes customer-safe fields.

Super admin rule:

- `super_admin` may enter `/app` for verification and support
- if no explicit customer context exists, the frontend shows a controlled empty state
- notice detail and document download endpoints still require a resolvable customer context and remain unavailable otherwise

## Norwegian-First Behavior

The customer frontend uses the canonical `CustomerContext` language resolution path.

For Norwegian customers/users:

- labels resolve in Norwegian
- CPV descriptions resolve in Norwegian only

There is no second frontend-only localization path.
