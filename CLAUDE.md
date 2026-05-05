# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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

System Owners can configure role-based permissions via the **Tilganger** tab in Kundemiljø. Stored as `permission_settings` JSON on `customers`. Three configurable permissions:

- `create_departments` — default: `['system_owner']`
- `create_users` — default: `['system_owner', 'bid_manager', 'contributor']`
- `view_all_cases` — default: `['system_owner', 'bid_manager', 'contributor']`

Key methods: `Customer::roleHasPermission()`, `User::canManageCustomerUsers()`, `User::canViewAllCasesViaSettings()`, `CustomerContext::canCreateCustomerDepartments()`.

Case visibility is enforced in `SavedNoticeAccessService::applyVisibility()`. Without `view_all_cases`, contributors/viewers only see cases they are directly involved in (saved_by, bid_manager, opportunity_owner, or explicit `SavedNoticeUserAccess`). Department-based fallback visibility is also gated by this setting.

Contributors with user management permission automatically get access to all customer departments when creating/editing users (via `CustomerContext::manageableDepartmentIds()`).

### Knowledge base document support

Upload validation accepts `docx`, `xlsx`, `pdf` (max 20 MB). Quality differs by format:

- **`.docx`** — full structured extraction: text, tables, images, H1/H2-based chunking with AI topic splitting
- **`.xlsx` and `.pdf`** — use `extractStructuredFallbackText`, meaning table/image handling is degraded compared to docx

Known gap: Excel and PDF are technically accepted but lack the same depth of chunking and structure extraction. This is an area for future improvement. Typical knowledge base content: service descriptions, standard texts, policies, CVs, references, and other reusable tender material.

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
