# "Vurdering" (assessment) consolidation — Enterprise Wiki as sole knowledge source

Branch: `refactor/ai-to-wiki-consolidation`. Final functional consolidation phase: moves the last
active Knowledge Base AI consumer ("Vurdering") onto Enterprise Wiki, closing the loop opened by
the two prior phases (answer-draft engine, then the answer-engine-as-sole-engine phase).

## Phase 1 — how "Vurdering" worked before this phase

- **Trigger**: a single bulk "Analyser krav" button inside the "Advanced AI" panel on the requirement
  card (`Show.jsx`, `refreshAssessments()`) — no per-requirement trigger exists or existed.
- **Route/controller**: `POST /ai/{savedNotice}/assessments/refresh` → `AiController::refreshAssessments()`,
  iterating every `approval_status = approved OR review_status = confirmed` requirement
  (`RequirementLoader::loadApprovedForCase()`) in one request.
- **Service**: `RequirementAssessmentService::assessRequirement()` — read up to 5 already-persisted
  `SavedNoticeAiEvidence` rows (selected evidence takes strict priority over suggested; no blending),
  each carrying `KnowledgeItem`/`KnowledgeItemChunk` fields, and sent them plus the requirement text
  and `ai_instructions` to one OpenAI call. The service did **not** itself run retrieval/ranking/
  embeddings — it only consumed evidence rows a separate, still-active pipeline
  (`AiController::refreshEvidence()` → `RequirementKnowledgeMatcher`) had already produced.
- **Output contract**: `coverage_status` (covered/partial/missing), `risk_level` (low/medium/high),
  `requirement_summary`, `coverage_rationale`, `missing_information`, `recommended_next_step` — all
  AI-authored in one JSON-schema call; `assessment_status` (completed/failed) is service/controller-
  managed, never AI-authored.
- **Storage**: `saved_notice_ai_requirement_assessments`, one row per requirement (unique constraint),
  `source_evidence_snapshot` (json) recorded exactly the evidence rows sent to the prompt.
- **Failure handling**: any `Throwable` from the service is caught by the controller and turned into a
  `FAILED` row (all content fields nulled) — a prior `COMPLETED` row is never downgraded.
- **Locale**: none — the system prompt hardcoded Norwegian output regardless of customer/user locale.
- **Downstream use**: none — confirmed the only consumer of a persisted assessment row is the Show.jsx
  display itself (no export, no other service reads it).
- **Standardvokabular**: confirmed zero influence at any point in this pipeline.

## Phase 2 — contract matrix (what "Vurdering" actually needs to produce)

| Funksjon | Kunnskapsbase-flyt | Wiki-erstatning | Gap | Tiltak |
|---|---|---|---|---|
| Input til vurderingen | Krav + inntil 5 kuraterte evidensrader | Krav + Wiki-sider funnet via research | Nei | `RequirementWikiResearchService::research()` |
| Kravtekst | Ja | Ja (uendret) | Nei | — |
| Kundekontekst | customer_id via evidensrader | customer_id eksplisitt til catalog builder | Nei | Uendret mønster |
| Rangerte kilder | `match_rank`/`is_primary` fra tidligere KB-matching | Wiki page-ranker (leksikalsk, deterministisk) | Nei | Gjenbruk Wiki sin ranker |
| Kildeutdrag | Chunk `content` | Page `content_markdown` (+ claim-tekster) | Nei | Gjenbruk Wiki sin pages-for-AI-transformasjon |
| Kildehenvisninger | Ikke eksponert til frontend før denne fasen | `wiki_sources_snapshot` + vist i UI | **Ja (nytt)** | Lagt til i payload + UI |
| Dekning/confidence | `coverage_status` (covered/partial/missing) | Samme 3 verdier beholdt (semantikk kompatibel) | Nei | Gjenbruk enum |
| Mulig konflikt | Fantes ikke | Ny `has_possible_conflict` boolean, mirrors Wiki-svar sitt mønster | **Ja (nytt, påkrevd av Fase 4)** | Ny kolonne + AI-felt |
| Vurderingsstatus | `assessment_status` (completed/failed), service-styrt | Uendret | Nei | — |
| Begrunnelse | `coverage_rationale` | Uendret semantikk, nå fra Wiki-sider | Nei | — |
| Risiko | `risk_level` (low/medium/high) | Uendret | Nei | — |
| Manglende kunnskap | `missing_information` | Uendret semantikk | Nei | — |
| Anbefalinger | `recommended_next_step` | Uendret semantikk | Nei | — |
| Språk/locale | Hardkodet norsk | `resolveLanguageCode()`, ekte locale-støtte | **Ja (lukket, ikke påkrevd men riktig)** | Nytt — matcher Wiki-svar sitt mønster |
| AI-instrukser | Ja, sakens `ai_instructions` | Ja, samme mønster + valgfri per-krav prompt (arkitektur-konsistens, ikke eksponert i UI ennå) | Nei | Videreført |
| Feil og retry | Kontroller fanger, lagrer FAILED-rad, bevarer COMPLETED | Uendret kontrakt | Nei | Samme mønster i ny tjeneste |
| Lagring | `saved_notice_ai_requirement_assessments` | Samme tabell, minimal migrasjon | Nei | Se Fase 5 |
| Visning | Show.jsx, Advanced AI-panel | Samme panel, + mulig-konflikt-merke + Wiki-kilder | Nei | UI oppdatert |
| Eksport | Ingen | Ingen (uendret, ingen behov identifisert) | Nei | — |
| Autorisasjon | `visibleAiSavedNotice` + `assertAiAccess` | Uendret | Nei | — |
| Kundescope | Via evidensradenes customer | customer_id eksplisitt til Wiki catalog builder | Nei | Strammere enn før |

**Konklusjon**: kontrakten "Vurdering" faktisk må produsere er uendret (samme 7 felt), pluss ett nytt,
eksplisitt påkrevd felt (`has_possible_conflict`). Ingen Kunnskapsbase-spesifikk detalj (chunk-index,
`match_rank`, embeddings) er en del av det faktiske produktbehovet — alt dette var implementasjonsdetaljer
i hvordan evidens ble funnet, ikke noe "Vurdering" selv trengte å vite om.

## Phase 3 — Wiki source and architecture

Reuses the exact same building blocks as the answer engine (`RequirementWikiAnswerService`):
`RequirementWikiResearchService::research()` for search/read/navigate (approved-and-current Wiki
pages only, customer-scoped, bounded), the same `claimTextsByPageIdAndOrigin()`/`pagesForAi()`
transformation pattern (duplicated narrowly rather than extracted into a shared class, to avoid
touching the already-tested answer-engine code for this phase), and the same source-based/
best-practice claim-origin split.

A new, narrow AI client (`RequirementWikiAssessmentAiClient`) and orchestrating service
(`RequirementWikiAssessmentService`) were built in `app/Services/Ai/Wiki/`, rather than refactoring
`RequirementAssessmentService` in place — matching the precedent already established twice in this
branch (`RequirementAnswerDraftService` → `RequirementWikiAnswerService`). Unlike the answer engine,
this is a **single AI call** producing the full judgment directly (no separate alignment/revision
pass) — assessment is a holistic judgment task, not a multi-section synthesis, so it doesn't need
that extra machinery. `RequirementAssessmentService` is deprecated (kept, unused, undeleted).

## Phase 4 — requirements met

- Assesses the requirement against relevant approved Wiki knowledge (research-bounded, customer-scoped).
- Explains why (`coverage_rationale`).
- Shows which Wiki pages were used (`wiki_sources_snapshot` → `wiki_sources` in the payload, rendered
  as a page-title list in the assessment panel).
- Distinguishes full/partial/none coverage (`coverage_status`: covered/partial/missing) **and**
  possible conflict (`has_possible_conflict`, a separate boolean — same orthogonal-axis pattern as the
  Wiki-answer engine's own `coverage_status` + `has_possible_conflict`, not a 4-value flat enum).
- Uses `ai_instructions` for tone/working-method only — the developer prompt explicitly forbids it
  from changing the coverage/risk/conflict judgment or overriding a page's documented facts.
- Preserves language via `resolveLanguageCode()` (a genuine improvement — the legacy service hardcoded
  Norwegian).
- Preserves customer scope (`customerId` passed explicitly to the Wiki catalog builder, never trusted
  from the requirement).
- Zero Wiki pages → a valid "missing" (or best-practice-reasoned) assessment, never an exception —
  the AI client is explicitly instructed to still write a summary/rationale/next-step using only the
  requirement text when no pages exist.

## Phase 5 — storage

Reused `saved_notice_ai_requirement_assessments` (Phase 5 explicitly favors reuse over a new table).
One non-destructive migration
(`2026_07_25_044436_add_wiki_fields_to_saved_notice_ai_requirement_assessments_table`):

- **Added** `has_possible_conflict` (boolean, nullable) and `engine_version` (string, nullable) —
  mirrors `saved_notice_ai_requirement_wiki_answers`'s own columns exactly.
- **Renamed** `source_evidence_snapshot` → `wiki_sources_snapshot` (plain `ALTER TABLE ... RENAME
  COLUMN`, no data loss). The column's purpose — a snapshot of what knowledge grounded the assessment
  — is unchanged; only the shape of what's inside moves from Knowledge-Base-chunk-shaped to
  Enterprise-Wiki-page-shaped. Existing rows are fully preserved and still readable under the new
  column name — nothing was backfilled or deleted.
- `coverage_status`/`risk_level`/the four text fields keep their exact original columns and enum
  values — old, Knowledge-Base-sourced assessment rows remain completely readable, indistinguishable
  in structure from new Wiki-sourced ones except for the (now-null-for-old-rows) new columns.

## Phase 6 — active flow transition

`AiController::refreshAssessments()` now calls `RequirementWikiAssessmentService::assessRequirement()`
instead of `RequirementAssessmentService::assessRequirement()`. Same bulk semantics (iterates
`loadApprovedForCase()`), same usage-guard operation key
(`AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH`), same failure handling
(`persistFailedRequirementAssessment()`, updated for the renamed column). `RequirementAssessmentService`
is marked `DEPRECATED` in its own docblock, kept, undeleted, and has zero remaining callers anywhere
in the codebase.

## Phase 7 — full codebase sweep: is Kunnskapsbase now consumer-free?

**No — one more active consumer was found, and this is reported here rather than hidden.**

`AiController::refreshEvidence()` (route `POST /ai/{savedNotice}/evidence/refresh`, button "Oppdater
kilder" in the same Advanced AI panel) still performs **live** Knowledge Base retrieval: keyword +
pgvector-embedding chunk matching (`RequirementKnowledgeMatcher`, `EmbeddingService`) against
`KnowledgeItemChunk`, persisting `SavedNoticeAiEvidence` rows. This is a real, still-wired, still-used
button — not dead code.

**Why this was not also migrated in this phase:** unlike answer-draft and assessment, "Oppdater
kilder" is not a text-generation flow with a single swappable knowledge source — it is a **manual,
human-curated evidence-selection tool** (the UI lets a person mark individual KB chunks as
selected/suggested/rejected evidence for a requirement, `updateEvidenceSelectionStatus()`). Enterprise
Wiki's retrieval model is fundamentally different: an AI agent automatically searches, reads, and
cites whole pages during research — there is no existing Wiki concept of "let a human browse and
manually curate individual knowledge fragments as evidence." Building that would be a new UX/product
design task (what does "select a piece of evidence" even mean against a Wiki page — the whole page? a
claim? a heading section?), not a retrieval-source swap. Forcing one into existence in this phase
would be exactly the kind of undiscussed behavior change the task's own stop conditions guard against.

**Consequence for Phase 8:** because this consumer remains genuinely active, Kunnskapsbase is **not**
fully consumer-free yet. Applying the same reasoning already used twice in this branch (menu items
stay visible when a real active dependency remains, documented rather than silently forced either
way): **Kunnskapsbase and "Bruk i AI" stay in the AI menu this phase too** — Kunnskapsbase because
"Oppdater kilder" reads its data live; "Bruk i AI" because it reports on that same still-active
evidence usage (not defunct history — its reporting role stays meaningful exactly because "Oppdater
kilder" is still live). Standardvokabular remains removed (unaffected by this finding).

Everything else confirmed fully disconnected: `MetadataRetrievalPlanService`, `MetadataCandidateRetrievalService`,
`KnowledgeMetadataMapService`, and `KnowledgeChunkCoverageService`'s AI-facing call sites are only
reachable from the now-dead `legacyGenerateRequirementAnswerDraft()` — zero other callers.
`KnowledgeMetadataTerm` (Standardvokabular) has zero references in `AiController.php` or any
`Requirements`/`Retrieval` service. A manual, non-scheduled CLI tool (`php artisan wiki:ingest`) can
bootstrap an Enterprise Wiki page from an existing `KnowledgeItemVersion`'s text — not reachable from
any HTTP route or schedule, flagged here as a residual manual bootstrap path, not an active flow.

## Phase 8 — AI menu

**No menu change in this phase** — see Phase 7's finding. Kunnskapsbase and "Bruk i AI" remain visible;
Standardvokabular remains removed; AI-instrukser remains; Enterprise Wiki remains its own top-level
area. This is the second time in this branch's history that a Phase 8-style menu removal has been
correctly deferred by its own conditional wording rather than forced — first for the answer-draft
engine's Kunnskapsbase dependency (resolved, since fixed), now for "Oppdater kilder"'s.

## Phase 9/10 — old routes, deprecation

No menu items were removed this phase, so no new "old route" redirect/410 handling was needed beyond
what the assessment refresh endpoint itself already does (it's the same route, `ai.requirements.assessment.refresh`,
now pointing at the Wiki service — no route change, no new dead route). `RequirementAssessmentService`
is the one class newly marked deprecated this phase (see Phase 6). No tables, columns, or data were
deleted; the one migration in this phase is additive/rename-only.
