# Microsoft Entra ID (SSO)

Entra authenticates the identity. Procynia decides everything else.

That split is the whole design. A token from Entra says *who* signed in. It does not say which
customer they belong to, what they may do, or whether their account is still active — those are read
from Procynia's own tables on every login. No Entra group, app role or directory role is mapped to a
Procynia role, and none ever should be without a separate, deliberate decision.

## Flow

```
GET /login/entra
  resolve provider (server-side, never from user input)
  generate state + nonce, store in session
  redirect to Microsoft

GET /login/entra/callback
  validate state           -> CSRF on the callback
  exchange code            -> server-to-server, with the client secret
  validate id_token        -> signature (tenant JWKS), issuer, audience, exp, nonce, tid
  resolve provider by tid  -> from the signed claim, not the session
  resolve Procynia user    -> known identity, or a single unambiguous first-time link
  verify active + customer + frontend access
  Auth::login + session()->regenerate()
  redirect to the app (or Filament for internal admins)
```

Authorization Code Flow with a confidential client. No implicit flow: the token never travels
through the browser.

## Data model

`identity_providers` — one row per (customer, Entra tenant).

| Column | Notes |
|---|---|
| `customer_id` | `null` means Procynia's own internal tenant |
| `tenant_id` | the Entra directory id |
| `client_id` | application id — configuration, not a secret |
| `issuer` | expected `iss`; stored so a sovereign cloud needs no code change |
| `is_enabled` | a disabled provider refuses sign-in |
| `allowed_email_domains` | optional extra gate for tenants containing guest accounts |

`user_identities` — links an external subject to a Procynia user.

| Column | Notes |
|---|---|
| `external_subject` | the `oid` claim — the stable identity key |
| `external_email` | recorded for support and first-time linking; never authenticates alone |

Unique on `(identity_provider_id, external_subject)` and `(user_id, identity_provider_id)`.

**No client secret is stored in the database.** It lives in config, sourced from env today and from
Key Vault later without touching the OIDC code.

## Multi-tenant

Customer A and customer B each get their own `identity_providers` row with their own tenant. The
provider carries the customer, so an identity arriving from tenant A can only ever resolve to a user
in customer A. Procynia's own administrators use a row with `customer_id = null`.

Nothing is hardcoded to one tenant. Adding a customer's tenant is a row, not a deploy.

## User linking

There is no auto-provisioning. A user must already exist in Procynia.

1. **Known identity** — `(provider, subject)` has been linked before. Straight through.
2. **First link** — no link yet, so match the email *within the provider's own customer*, and only
   if exactly one active candidate exists.
3. **Otherwise refuse.** No user is created, no role assumed.

Creating a user here would mean inventing a customer and a role from an external claim, which is
exactly the authority Entra is not being given.

`users.email` currently carries a global unique index, so the same address cannot exist in two
customers. The ambiguity guard remains anyway: that index is the only thing making the case
unreachable, and per-customer emails would be a reasonable future change.

## Customer isolation

An Entra identity cannot be linked to the wrong customer. Three independent guards:

1. The provider is resolved from the **signed `tid` claim**, not from the session or a query
   parameter. A caller cannot choose which customer to be resolved against.
2. First-time linking is restricted to the provider's own customer (or to users with no customer,
   for the internal provider).
3. The customer is re-verified on **every** login, not just at linking. Moving a user to another
   customer breaks the existing link rather than silently carrying it over.

## Login modes

Two independent switches, because a customer migrating to SSO needs a window where both work.

| `AUTH_ENTRA_ENABLED` | `AUTH_LOCAL_LOGIN_ENABLED` | Result |
|---|---|---|
| `false` | `true` | Today's behaviour. Local development. Entra routes 404. |
| `true` | `true` | Migration window — both offered on the login page. |
| `true` | `false` | SSO only. `POST /login` returns 404, not just a hidden form. |
| `false` | `false` | Refused as a misconfiguration — it would lock everyone out. |

Neither is derived from `APP_ENV`. Production must be able to run either mode, and a developer must
be able to exercise the Entra path locally against a test tenant.

**Local development needs no Azure.** The defaults are Entra off, local login on.

## Configuration

| Config | Secret? | Source |
|---|---|---|
| `AUTH_ENTRA_ENABLED` | no | env |
| `AUTH_LOCAL_LOGIN_ENABLED` | no | env |
| `AUTH_ENTRA_REDIRECT_URI` | no | env |
| `AUTH_ENTRA_CLIENT_SECRET` | **yes** | env today, Key Vault later |
| tenant id, client id, issuer | no | `identity_providers` table |

The redirect URI must be HTTPS outside local development — an authorization code delivered over
plain HTTP is a code an intermediary can read. `ops:runtime-check` enforces this.

Scopes are `openid profile email` and nothing more. Procynia reads no directory data and calls no
Graph endpoint, so anything wider would be permission it never uses.

## Sessions and tokens

After login the browser holds an ordinary hardened Procynia session (finding F-06), created with
`session()->regenerate()` to close session fixation — exactly as local login does.

**No Entra token is stored anywhere.** The access token is discarded after the exchange, no refresh
token is requested, and nothing is written to the frontend or `localStorage`. A token that is never
kept cannot leak. If Microsoft Graph is ever needed, that is a separate requirement with its own
review.

## Logout

`POST /logout` invalidates the Procynia session and regenerates the CSRF token. Unchanged by SSO.

This is a **local logout**: the user is signed out of Procynia, not out of Microsoft. Returning to
`/login/entra` will sign them straight back in if their Microsoft session is still active.

Federated logout (redirecting to Microsoft's `end_session_endpoint`) is deliberately not implemented.
It signs the user out of every Microsoft-backed application in that browser, which is rarely what
someone leaving one tab intends. It can be added per customer if a customer asks for it.

## Break-glass

Do not remove every local login without a recovery path.

Recommended: keep at least one internal Procynia administrator able to sign in locally
(`AUTH_LOCAL_LOGIN_ENABLED=true` for the internal deployment), so a tenant outage, an expired client
secret or a misconfigured app registration does not lock out the people who would fix it.

This is a documented operational choice, not a backdoor: the account is an ordinary user subject to
every existing control, and its password is subject to F-01 throttling.

## Runtime check

`ops:runtime-check` fails a production deploy where Entra is enabled but unusable:

- missing client secret
- missing, malformed or non-HTTPS redirect URI
- enabled with no identity provider configured
- both login methods disabled

It never prints the secret.

## Security logging

| Event | When |
|---|---|
| `entra_authentication_succeeded` | sign-in completed |
| `entra_authentication_failed` | any refusal, with an internal reason code |
| `entra_identity_linked` | an external identity linked to an existing user for the first time |

Never logged: authorization codes, access or ID tokens, the client secret, or raw claims.

The user-facing message is identical for every refusal. Telling a caller "no such user" or "wrong
customer" would turn the login page into a directory oracle; the reason is logged internally
instead.

## Test coverage

`tests/Feature/Security/EntraAuthenticationTest.php` — 25 tests, no external calls. The OIDC client
is replaced with a double, because the interesting behaviour is not whether the library speaks OIDC
but what Procynia does with a validated set of claims: cross-tenant refusal, inactive users, unknown
identities, ambiguity, disabled providers, domain allow-lists, session regeneration, the login-mode
matrix, and that claims carrying `roles`/`groups`/`wids` grant nothing.
