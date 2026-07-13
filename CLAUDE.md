# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Procynia strategidoktrine – følges i all videre utvikling

Procynia skal ikke posisjoneres som en enkel AI-skriveassistent eller et gratis anbudsvarsel-produkt.

Procynia skal posisjoneres som: **"Styringssystemet for virksomheter som lever av anbud."**

**Konkurrentbildet:** Cobrief er tydelig posisjonert rundt AI for anbud, gratis anbudsvarsling, rask oppstart og tidsbesparelse. Procynia skal ikke kopiere dette budskapet direkte.

**Procynias differensiering** — Procynia eier hele den operative tilbudsprosessen:
fra mulighet → vurdering → ansvar → kravarbeid → dokumentasjon → kvalitetssikring → levert tilbud → sporbarhet og gjenbruk.

**Hovedbudskap:** Anbudsarbeid feiler ikke bare fordi man mangler tekst. Det feiler fordi ansvar, krav, dokumentasjon, beslutninger, frister og eierskap ligger spredt.

**Procynia vektlegger:** kontroll, ansvar, struktur, sporbarhet, beslutningsstøtte, kravstyring, dokumentasjonskontroll, teamarbeid, enterprise/sikkerhet/compliance, og trygg bruk av AI som støtte — ikke erstatning for faglig vurdering.

**Unngå:**
- Generisk "spar tid med AI"
- "Superkrefter" / "AI skriver tilbudet for deg"
- For mye teknisk språk
- Å fremstå som et enkelt søke-/varslingsverktøy
- Å kopiere Cobrief sin posisjon

**Bruk heller formuleringer som:**
- bedre kontroll på neste anbud
- fra kunngjøring til levert tilbud
- samle krav, ansvar og dokumentasjon
- prioriter riktige muligheter
- reduser risiko før tilbudet sendes
- styr hele tilbudsarbeidet på ett sted
- AI-støtte med struktur, kilder og menneskelig kontroll

Alle nye sider, tekster og funksjoner vurderes mot denne posisjoneringen før implementering.

## What is Procynia

Procynia is a Norwegian public procurement (Doffin) bid management system. It pulls tender notices from the Doffin API, routes them to customer departments via watch profiles, and provides an AI-supported bid execution layer — extracting requirements from tender documents, matching them against a customer knowledge base, and generating grounded answer drafts.

Core principle: AI supports structure, it does not replace it. All AI output must be grounded in sources and traceable to documents. See `docs/readme-ai.md` for the full AI product strategy.

Typical users are bid managers, sellers, presales resources, technical contributors, and others involved in tender response work. They are not developers — UI must be intuitive and workflow-oriented.

## Stack

- **Backend**: Laravel 13, PHP 8.3
- **Frontend**: Inertia.js + React 19 + Tailwind CSS 4
- **Admin panel**: Filament 5 (internal admin only, at `/admin`)
- **Database**: PostgreSQL 14.14 (Homebrew, local). Embeddings are stored as `json` in `knowledge_item_chunks.embedding_vector` — pgvector is not installed and not currently in use despite being mentioned in `docs/readme-ai.md`.
- **AI**: OpenAI API via `app/Services/OpenAi/OpenAiClient.php`. Model split: `gpt-4.1-mini` for extraction/metadata/classification, `gpt-5` for answer draft generation.
- **Queue**: Named queues — `ai-requirements`, `default`, `supplier-harvests`

## Commands

```bash
# Start full dev environment (server + queue worker + log watcher + vite)
composer dev

# Build frontend assets
npm run build

# Run all tests
composer test

# Run a single test
php artisan test --filter=TestName

# PHP linting (Laravel Pint)
./vendor/bin/pint

# Doffin supplier harvest (separate queue)
php artisan doffin:harvest-suppliers --from=YYYY-MM-DD --to=YYYY-MM-DD --type=RESULT
php artisan queue:work --queue=supplier-harvests,default

# Fresh setup
composer setup
```

### Vite hot mode and `public/hot`

- `public/hot` is a local Vite dev artifact created when `npm run dev` or `composer dev` starts the Vite dev server.
- When `public/hot` exists, Laravel uses the Vite dev server instead of `public/build/manifest.json`.
- If `public/hot` points to a dead or wrong dev server, `/login` and `/app` can render as a white page.
- If you want to use built assets, `public/hot` should not exist.
- If `/login` or `/app` is white, check `public/hot` first. If `public/build/manifest.json` exists and Vite dev is not running, it is safe to remove `public/hot`.
- Do not commit `public/hot`; it is ignored by git and should remain local.

## Architecture

### Two-panel structure

The app has two separate surfaces:

1. **Filament admin panel** (`/admin`) — internal admins only. Manages customers, users, Doffin import runs, sync logs, watch profiles, and notice routing. Resources live in `app/Filament/Resources/`.

2. **Customer frontend** (`/app`) — customer-facing React/Inertia SPA. All routes require `auth` + `customer.frontend` middleware. Controllers in `app/Http/Controllers/App/`. Pages in `resources/js/Pages/App/`.

### Multi-tenancy model

Tenancy is customer-scoped. The canonical resolver is `app/Support/CustomerContext.php`.

- **Global shared data**: Doffin notices, CPV catalog, import runs — imported once, shared across all customers.
- **Customer-owned data**: departments, watch profiles, users, notice decisions, attentions, AI bid cases.
- **Notice visibility**: A notice appears in the customer frontend only when a `notice_attentions` row exists for that customer.

Watch profiles route notices into departments. Departments belong to customers. Cross-customer leakage is prevented by scoping all selectors and queries to `customer_id`.

Users have two separate role fields:
- `users.role` — system access: `super_admin` (internal, `customer_id = NULL`), `customer_admin`, `user`
- `users.bid_role` — bid function in the customer frontend: `system_owner`, `bid_manager`, `contributor`

`bid_manager` users also have a `bid_manager_scope` field and a `bid_manager_departments` pivot table controlling which departments they manage. Language resolves: user preference → user nationality → customer language → `no` fallback.

### AI bid case engine

The AI layer (`app/Services/Ai/`) is organized into three subdirectories:

- **`Requirements/`** — extracts requirements from uploaded tender documents using an AI pipeline: `DocumentSplitPlanner` decides whether to process full-document or split by segments, `RequirementExtractionPipeline` orchestrates the extraction call, `RequirementAnswerDraftService` generates grounded answer drafts, `RequirementAnswerBasisService` retrieves supporting knowledge chunks.
- **`Knowledge/`** — manages the customer knowledge base: chunking documents (H1/H2 structural boundaries, AI returns only topic split points not text), generating embeddings, metadata tagging, and vocabulary extraction.
- **`Retrieval/`** — retrieves relevant knowledge chunks for a given requirement using metadata-based retrieval plans before falling back to semantic search.

AI jobs run on the `ai-requirements` queue. See `app/Jobs/Ai/` and `app/Jobs/GenerateKnowledgeChunk*.php`.

### Answering strategy (three scenarios)

Defined in `docs/answering-strategy.md`:
1. **Full coverage** — complete, bid-ready draft; quality assurance confirms strong documentation.
2. **Partial coverage** — complete draft generated using both knowledge chunks and domain knowledge; QA clearly marks what is documented vs. professionally inferred vs. missing.
3. **No coverage** — no standard answer generated; system explains what documentation is missing.

Procynia must never present professionally inferred content as document-backed.

### Chunking strategy

Defined in `docs/chunking-strategy.md`. H1/H2 headings are hard structural boundaries — AI never mixes content across H2 sections. AI returns only block ID ranges (not text) to indicate topic splits; backend assembles the actual chunks from original text. This prevents AI from inventing, shortening, or misattributing content.

### Key models

`SavedNotice` (bid case), `SavedNoticeAiDocument`, `SavedNoticeAiDocumentChunk`, `SavedNoticeAiRequirement`, `SavedNoticeAiRequirementAssessment`, `KnowledgeItem`, `KnowledgeItemChunk`, `KnowledgeMetadataTerm`, `Notice`, `Customer`, `Department`, `WatchProfile`.

### Customer permission settings

System Owners can configure role-based permissions via the **Tilganger** tab in Kundemiljø. Stored as `permission_settings` JSON on `customers`. Four configurable permissions:

- `create_departments` — default: `['system_owner']`
- `create_users` — default: `['system_owner', 'bid_manager', 'contributor']`
- `view_all_cases` — default: `['system_owner', 'bid_manager', 'contributor']`
- `approve_wiki_claims` — default: `['system_owner', 'qa']`

Key methods: `Customer::roleHasPermission()`, `User::canManageCustomerUsers()`, `User::canViewAllCasesViaSettings()`, `User::canApproveWikiClaims()`, `CustomerContext::canCreateCustomerDepartments()`.

Case visibility is enforced in `SavedNoticeAccessService::applyVisibility()`. Without `view_all_cases`, contributors/viewers only see cases they are directly involved in (saved_by, bid_manager, opportunity_owner, or explicit `SavedNoticeUserAccess`). Department-based fallback visibility is also gated by this setting.

Contributors with user management permission automatically get access to all customer departments when creating/editing users (via `CustomerContext::manageableDepartmentIds()`).

#### QA (additive capability, not a role)

`users.is_qa` is a boolean flag layered on top of a user's ordinary `bid_role` — it is never a replacement for the role, and a user keeps their existing role (`bid_manager`, `contributor`, ...) when QA is toggled on or off. Because a user belongs to exactly one customer (`users.customer_id`), `is_qa` is already customer-scoped the same way `bid_role` is — no separate customer-membership pivot was needed.

`Customer::roleHasPermission(string $bidRole, string $permission, bool $isQa = false)` treats `"qa"` as another valid entry in a permission's roles list (alongside `bid_manager`, `contributor`, `"all"`) — it is not a mutually-exclusive role value `$bidRole` can hold. Every existing permission check (`canManageCustomerUsers()`, `canCreateCustomerDepartments()`, `canViewAllCasesViaSettings()`) now passes `$user->isQa()` through, so the **QA** column in the Tilganger gallery works uniformly for every permission row, not just the new one. System Owner always passes regardless of QA (`roleHasPermission()` short-circuits `true` for `system_owner` before consulting the roles list).

`User::canApproveWikiClaims()` gates manual Wiki claim approval/undo (`WikiClaimController::approve()`/`unapprove()`) — System Owner, or any role with QA and effective access to `approve_wiki_claims`. This is a separate permission from whole-page approve/reject (`WikiController::approve()`/`reject()`), which remains System Owner-only; QA never grants page-level approval. `WikiController::visibleStatuses()` also grants draft/pending_review read access via `canApproveWikiClaims()`, so a Contributor+QA can open the page and verification basis needed to act on claims — this is the only additional visibility QA grants; it does not touch Wiki administration, document import, source deletion, or any other admin action.

QA is administered in the existing "Brukere" tab (`UserController::store()`/`update()`) using the same authorization as editing `bid_role` (System Owner only, never on oneself) — no new admin capability was introduced.

### Knowledge base document support

Upload validation accepts `docx`, `xlsx`, `pdf` (max 20 MB). Quality differs by format:

- **`.docx`** — full structured extraction: text, tables, images, H1/H2-based chunking with AI topic splitting
- **`.pdf`** — extracted via `pdftotext` binary (path in `config/services.php` → `services.pdftotext.binary`); structural heuristics applied, but no table/image extraction
- **`.xlsx`** — uses `extractStructuredFallbackText`; degraded compared to docx

Typical knowledge base content: service descriptions, standard texts, policies, CVs, references, and other reusable tender material.

### Internationalisation (i18n)

**All new UI strings must be internationalised — no hardcoded Norwegian in new code.**

#### How it works

- Lang files: `lang/no/procynia.php` and `lang/en/procynia.php`, nested by domain (`ai.*`, `frontend.*`, `common.*`, etc.)
- Backend shares translations via `HandleInertiaRequests::share()` under the `translations` prop
- Frontend access: destructure from `usePage().props` or use the `useTranslations` hook (`resources/js/Support/useTranslations.js`)

#### Pattern for new React pages

```jsx
const { translations = {} } = usePage().props;
const tf = translations?.frontend ?? {};   // or translations?.ai, translations?.common, etc.

// Always include a Norwegian fallback string
<button>{tf.save_button ?? 'Lagre'}</button>
```

#### Pattern for new strings

1. Add the key to **both** `lang/no/procynia.php` and `lang/en/procynia.php`
2. Share it in `HandleInertiaRequests::share()` under the appropriate namespace
3. Use it in React via the `translations` prop with a Norwegian fallback

#### AI service prompts

AI prompts must not hardcode a language. Use `CustomerContext::resolveLanguageCode()` in HTTP controllers and resolve from `$model->customer->language->code` in queue jobs. Pass the resulting `$languageCode` to all AI service methods that produce user-facing text. The `languageName(string $code)` helper (defined locally in each AI service) maps codes to English language names for insertion into prompts.

#### Status

Infrastructure is complete. Most existing pages still have hardcoded Norwegian — these will be migrated incrementally. Do not retroactively fix pages unless explicitly asked.

### Billing and subscriptions (planned)

Planned implementation: **Stripe + Laravel Cashier**. Not yet built — do not assume any billing code exists.

Two distinct concerns:

1. **Procynia as product owner** — automated subscription billing, renewals, cancellations, PDF invoices, failed payment retries. All handled by Stripe/Cashier, nothing custom-built. Filament resource shows Stripe subscription data (status, history, upgrade/downgrade/cancel actions) — data is fetched from Stripe, not stored in the local database.

2. **System Owner self-service** — a protected tab in the customer frontend where System Owners can view their invoices, next renewal date, and trigger cancellation. All data comes from Stripe API.

Design principle: keep billing logic entirely in Stripe/Cashier; the app only reads and displays Stripe state.

### Doffin integration

`app/Services/Doffin/` handles API calls to the Norwegian Doffin procurement platform. Config in `config/doffin.php`. Supplier harvest runs on the `supplier-harvests` queue. The relevance scoring engine uses weighted CPV matches, keyword matches, deadline bonuses, and type bonuses (weights configurable in `config/doffin.php`).

## Open work items

These are known issues/features being actively worked on (from internal backlog). Do not "fix" these unless explicitly asked — some are intentional temporary state.

**Leave as-is for now:**
- Nr. 1: Statusfeltet i AI → Oversikt er misvisende — bevisst utsatt
- Nr. 2: Bilde i AI → Oversikt vurderes fjernet — bevisst utsatt

**Åpne (ikke ferdig):**
- Nr. 4: Definer hva "aktivitet på saker" betyr; tydeliggjøre Cockpit-scope
- Nr. 5: Fjerne noen elementer fra Oversikt-siden
- Nr. 10: Bedre håndtering av selskapsvokabular — hvordan ekstraheres og brukes for `topic`/`sub_topic` på chunks
- Nr. 24: Ikke definert ennå

**Ferdig:**
Nr. 3, 6, 7, 8, 9, 11, 12a, 12b, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23

## Project docs

- `docs/readme-ai.md` — AI product definition, development phases, key rules
- `docs/customer-tenancy.md` — tenancy model, data ownership categories, role rules, onboarding flow
- `docs/customer-frontend.md` — frontend route structure, document download, tenant safety
- `docs/answering-strategy.md` — three-scenario AI answering strategy (Norwegian)
- `docs/chunking-strategy.md` — document chunking rules and AI split protocol
- `docs/cpv-catalog.md` — CPV code catalog model
- `docs/doffin-supplier-harvest.md` — supplier harvest queue and CLI usage

# Autonomous programming workflow

For programming tasks in this repository:

- Do not ask for approval for routine implementation decisions.
- Do not present technical options and wait for the user to choose when one reasonable solution can be selected.
- Inspect the codebase, make reasonable engineering decisions, implement the requested change, run the relevant tests, and commit the completed work.
- Do not stop before commit unless the user explicitly says not to commit.
- Report decisions, assumptions, changed files, tests, and commit hash after completion.
- Prefer the smallest coherent solution that satisfies the requested behavior.
- Preserve unrelated local changes.
- Do not perform unrelated refactors.

Only stop and ask the user when the task would require:

- destructive or irreversible operations
- modifying or deleting production data
- deploying to production
- exposing, rotating, or changing secrets or credentials
- incurring material external cost
- resolving a genuine product ambiguity that materially changes intended behavior

Routine code structure, naming, tests, migrations, queue configuration, and implementation details are engineering decisions the agent should make autonomously.
