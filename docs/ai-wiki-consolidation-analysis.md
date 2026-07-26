# AI → Enterprise Wiki consolidation analysis

Branch: `refactor/ai-to-wiki-consolidation`. Status: living document for a phased architecture change — Enterprise Wiki becomes Procynia's primary, shared knowledge layer; AI-instrukser stays a separate governance layer for *how* AI uses that knowledge, never an alternative fact store.

Target state, in one sentence: **Enterprise Wiki = hva Procynia vet. AI-instrukser = hvordan AI skal bruke det Procynia vet.**

## Phase 1 — dependency map per legacy area

### 1. Standardvokabular (`KnowledgeMetadataTerm`, `KnowledgeMetadataTermSuggestion`, `KnowledgeVocabularyAnalysisBatch`)

- **Menu**: `AI → Standardvokabular` (`resources/js/Layouts/CustomerAppLayout.jsx`, `secondaryNavigation` for `activeMainArea === 'ai'`).
- **Frontend**: `resources/js/Pages/App/AI/KnowledgeVocabulary/Index.jsx`.
- **Routes/controller**: `app.ai.knowledge-vocabulary.*` in `routes/app.php` → `App\Http\Controllers\App\KnowledgeVocabularyController` (`index`, `storeBatch`, term update/delete, suggestion approve/edit-and-approve/reject/merge).
- **Models/tables**: `knowledge_metadata_terms`, `knowledge_metadata_term_suggestions`, `knowledge_vocabulary_analysis_batches`.
- **Services**: vocabulary analysis batch runs through `App\Jobs\Ai\*` extraction jobs into suggestions; enforcement at ingestion time is `App\Services\Ai\Knowledge\KnowledgeChunkMetadataValidator`.
- **Actual consumers of the *type* enum**: of the 11 `KnowledgeMetadataTerm::TYPES`, only `service_product_tag` and `theme_tag` are read back by `KnowledgeChunkMetadataValidator` during Knowledge Base chunk ingestion. `topic`/`sub_topic` and the remaining types are stored and rendered in the UI but are not enforced or consumed by any AI prompt, search, or matching function — confirms CLAUDE.md's own open backlog item **Nr. 10** ("Bedre håndtering av selskapsvokabular") is real and unresolved.
- **Never reaches** `app/Services/Ai/Requirements/` or `app/Services/Ai/Retrieval/` — no requirement extraction, retrieval plan, or answer-draft prompt reads vocabulary terms directly.
- **Tests**: `tests/Feature/App/KnowledgeVocabularyControllerTest.php`.

### 2. Kunnskapsbase (`KnowledgeItem`, `KnowledgeItemChunk`)

- **Menu**: `AI → Kunnskapsbase`.
- **Frontend/controller**: knowledge base upload, chunk browsing, and management pages/controllers under `App\Http\Controllers\App\` (Knowledge* controllers).
- **Models/tables**: `knowledge_items`, `knowledge_item_chunks` (embeddings stored as `json` in `embedding_vector`, similarity via `App\Support\CosineSimilarity`, pgvector not installed).
- **Active AI consumer — the primary answer-generation path**: `App\Services\Ai\Requirements\RequirementAnswerDraftService::ensureAnswerDraft()` combines `SavedNoticeAiEvidence` (curated chunk citations), a fresh `retrievedKnowledgeChunksForRequirement()` pass, `answer_basis_items`, `RequirementGroundingJudgeService`, and `case_instructions` (`ai_instructions`) into the default gpt-5 answer draft. This is the **primary, most mature, default** answer path for every bid case today.
- **Retrieval**: `app/Services/Ai/Retrieval/` builds metadata-based retrieval plans before falling back to semantic (embedding) search over `knowledge_item_chunks`.
- **"Bruk i AI"** (`KnowledgeBaseAiUsageController`) is a read-only reporting view over `SavedNoticeAiEvidence` aggregates — confirmed to have zero write effect on system behavior; it only shows *how much* Kunnskapsbase content has been cited.
- **Tests**: existing Knowledge Base + `RequirementAnswerDraftService` test suites (untouched by this phase).

### 3. AI-instrukser (`saved_notices.ai_instructions`)

- **Menu**: `AI → AI-instrukser`, kept — never a phase-out candidate, only re-scoped conceptually.
- **Frontend**: `resources/js/Pages/App/AI/Instructions.jsx`.
- **Storage**: `ai_instructions` — a `text` nullable column on `saved_notices` (migration `2026_04_19_000002_add_ai_instructions_to_saved_notices_table.php`), scoped **per bid case**, not per customer.
- **Consumers before this phase**: only `RequirementAnswerDraftService` (as `case_instructions`) — the Wiki-answer engine did not receive it at all.
- **Consumers after this phase** (see Phase 3 below): also `RequirementWikiAnswerService` → `RequirementWikiAnswerAiClient`, as a subordinate style/tone directive, explicitly forbidden from overriding documented facts, citations, or the anti-fabrication boundary.

### 4. Enterprise Wiki (existing capability inventory)

- `EnterpriseWikiPage` → `EnterpriseWikiPageVersion` (one `is_current`) → `EnterpriseWikiClaim` (`content_origin`: unclassified / source_based / best_practice / unsupported_generated_content / internal_error), optional `EnterpriseWikiCanonicalFact`, `EnterpriseWikiSourceReference` (provenance), `EnterpriseWikiPageLink` (relations, parsed from `content_markdown` wikilinks — single source of truth).
- No embeddings/chunks in Wiki — page ranking is lexical/token-overlap (`RequirementWikiPageRanker`), not semantic.
- Existing Wiki-answer engine ("Fase 9", `app/Services/Ai/Wiki/`): `RequirementWikiCatalogBuilder` (customer + approved-status filtered catalog) → `RequirementWikiResearchService` (search → read → navigate → synthesize) → `RequirementWikiAnswerService` (orchestrator) → `RequirementWikiAnswerAiClient` (drafts the answer) → `RequirementWikiAlignmentAiClient` (aligned / partially_aligned / best_practice / possible_conflict) → `RequirementWikiAnswerRevisionAiClient` (single bounded revision pass, conflict cases only). Persists to `saved_notice_ai_requirement_wiki_answers` (`SavedNoticeAiRequirementWikiAnswer`) — a separate table from the legacy `saved_notice_ai_requirements.answer_draft_*` columns, so the two answer paths do not collide.

## Phase 2 — data snapshot (read-only, local/dev DB, 2026-07-23)

No migration, deletion, or backfill was run to produce these numbers.

| Area | Metric | Value |
|---|---|---|
| Standardvokabular | `knowledge_metadata_terms` (approved) | 48 |
| Standardvokabular | `knowledge_metadata_term_suggestions` | 56, **all still `pending`** — none acted on since 2026-07-07 |
| Standardvokabular | `knowledge_vocabulary_analysis_batches` | 0 — no analysis has ever been run |
| Kunnskapsbase | `knowledge_items` | 1 (customer_id 4) |
| Kunnskapsbase | `knowledge_item_chunks` | 30 |
| Kunnskapsbase | `saved_notice_ai_evidence` | 0 in this environment |
| AI-instrukser | `saved_notices.ai_instructions` populated | 0 in this environment |
| Wiki-answer engine | `saved_notice_ai_requirement_wiki_answers` | 6 |
| Enterprise Wiki | `enterprise_wiki_pages` | 32, spanning customer_ids 2, 4, 1538 |
| Customers | total | 5 |

**Reading**: Standardvokabular shows near-zero real usage (0 batches ever, 56 untouched stale suggestions) and a narrow, only-partially-enforced technical footprint — safe to deprecate. Kunnskapsbase is small in this environment but is structurally the **only** content source for the still-primary `RequirementAnswerDraftService` flow — it cannot be hidden or deprecated without first replacing that flow, which this phase does not do (see Phase 4 decision below).

**Duplicates / overlap with Wiki**: none of Standardvokabular's term data is mirrored in Wiki (Wiki has no controlled-vocabulary concept); Kunnskapsbase chunk content is conceptually the same *kind* of material Wiki pages hold (service descriptions, policies, reusable tender material), but there is no automatic migration path — a knowledge item would need to become one or more Wiki pages with their own claims/source references, which is a deliberate content-modeling decision left for a future, separate task, not a mechanical migration.

## Transition matrix

| Område | Dagens ansvar | Datakilde | Aktive konsumenter | Dekkes av Wiki | Gap | Tiltak |
|---|---|---|---|---|---|---|
| Standardvokabular | Kontrollert vokabular for chunk-metadata (i praksis kun 2 av 11 typer) | `knowledge_metadata_terms(_suggestions)` | `KnowledgeChunkMetadataValidator` (kun `service_product_tag`, `theme_tag`) | Nei — Wiki har ikke et vokabular-konsept | Ingen reelt tap: nesten ubrukt (0 batches, 56 urørte forslag) | **Deprecate**: skjul menypunkt, blokker nye batches (500→kontrollert redirect+flash), behold lesetilgang til eksisterende data |
| Kunnskapsbase | Eneste innholdskilde for primær svarmotor (`RequirementAnswerDraftService`) | `knowledge_items(_chunks)` | `RequirementAnswerDraftService`, `RequirementAnswerBasisService`, `app/Services/Ai/Retrieval/` | Delvis — Wiki dekker "hva Procynia vet" konseptuelt, men har ingen chunks/embeddings og er ikke koblet til denne motoren | Reell avhengighet består: kan ikke fjernes uten å erstatte primærmotoren (Fase 10-beslutning, egen ADR) | **Behold** synlig og fullt funksjonell; ingen skjuling nå |
| Bruk i AI | Ren rapportering (lesevisning) over `saved_notice_ai_evidence` | Avledet, ingen egen tabell | Ingen — kun visning | Ja, konseptuelt dekket av Wiki sin kildesporbarhet, men rapporterer i dag på Kunnskapsbase, ikke Wiki | Rapporten mister mening før Kunnskapsbase er erstattet | **Behold** synlig så lenge Kunnskapsbase er aktiv (samme betingelse som over) |
| AI-instrukser | Styrer tone/stil/arbeidsmåte for AI-svar, per sak | `saved_notices.ai_instructions` | `RequirementAnswerDraftService` (allerede), **nå også** `RequirementWikiAnswerService`/`RequirementWikiAnswerAiClient` | Ja — kobles eksplisitt til Wiki-kontekst i denne fasen | Lukket i denne fasen | **Behold og koble**: prompt-lagdeling implementert (se Fase 3) |
| Enterprise Wiki | Felles kunnskapslag: konsepter, kilder, canonical facts, sporbarhet | `enterprise_wiki_*` | `RequirementWiki*`-motoren (Fase 9) | — (er selve målet) | Ingen ny claim-verifikasjons-/canonical-fact-status-gate i `RequirementWiki*` ennå (se "Kjent gap" under) | **Behold som egen toppnivå-seksjon**, ikke flyttet under AI |

Risiko og rollback: alle endringer i denne fasen er additive eller kontrollert-blokkerende (ingen destructive migrasjon, ingen sletting av tabeller/rader). Rollback for hvert commit er en ren `git revert` — `legacyStoreBatch()` beholder original logikk uendret i kildekoden slik at gjenåpning av batch-oppretting er en én-linjers endring, ikke en gjenoppbygging.

## Known, deliberately unaddressed gap

`RequirementWiki*` today filters Wiki pages by customer + approval status (via `RequirementWikiCatalogBuilder`) but does **not** additionally gate on individual claim-verification status or canonical-fact staleness before citing a page. Adding that gate is a genuine product/behavior decision (it could materially reduce answer coverage, since much Wiki content is still unverified/pending) rather than a bug fix, and is out of scope for this phase — flagged here for a future, separate decision rather than silently implemented.

## Product-ambiguity finding: Kunnskapsbase / "Bruk i AI" stay visible

The task's own Phase 4 instruction is conditional: *"Fjern eller skjul fra AI-menyen... når aktive konsumenter er flyttet."* That condition is not met for Kunnskapsbase — it remains the sole content source for `RequirementAnswerDraftService`, which `docs/enterprise-llm-wiki-plan.md` explicitly keeps **"helt uendret"** pending a future, ADR-gated Fase 10 decision on whether to replace it with the Wiki-answer engine. Hiding Kunnskapsbase or "Bruk i AI" now would break the primary, default answer-generation feature for every customer. Per the task's own logic and its explicit stop condition for genuine product ambiguity that changes expected user behavior, this phase:

- **Removes** the Standardvokabular menu entry (safe — see transition matrix).
- **Keeps** Kunnskapsbase and "Bruk i AI" visible and fully functional.
- **Documents** this as the deliberate, conditional outcome of the user's own instruction, not a deviation from it.

## Phase 3 — implemented in this phase

`RequirementWikiAnswerService::generate()` and `RequirementWikiAnswerAiClient::generateAnswer()`/`buildPayload()` now accept an optional `caseInstructions` parameter, sourced from `SavedNotice::ai_instructions` at the `AiController::generateRequirementWikiAnswer()` call site. Prompt layering, in priority order:

1. System/safety instructions (developer prompt: anti-fabrication rules, citation requirements).
2. Case instructions (`ai_instructions`) — tone, terminology, style, capitalization only.
3. Approved Wiki context (pages read during research).
4. The concrete requirement being answered.

Case instructions are explicitly barred, in both the developer prompt and a code comment, from overriding a page's documented facts, removing/changing a citation, or weakening the anti-fabrication boundary — enforced by instruction text to the model (not a separate code-level filter), consistent with "AI-instrukser skal ikke være et alternativt faktalager." Case instructions are deliberately **not** forwarded to `RequirementWikiAnswerRevisionAiClient` — the revision pass exists solely to resolve a `possible_conflict` against Wiki facts, and is kept free of style-only steering to keep that pass narrowly scoped.

Customer scope, approval-status filtering, and source-reference/traceability in the Wiki-answer engine were already correct prior to this phase (verified, not modified) — `RequirementWikiCatalogBuilder` already filters by customer and approval status, and every answer section already carries `used_page_ids`/citations back to source pages.

UI: `Instructions.jsx` gained a subtitle ("Enterprise Wiki er hva Procynia vet. AI-instrukser styrer hvordan AI-en skal bruke den kunnskapen...") and a new first help-panel section explaining the Wiki relationship, in both `lang/no/procynia.php` and `lang/en/procynia.php`.

## Phase 4 — menu/UI (this phase)

- **Removed**: `AI → Standardvokabular` entry from `CustomerAppLayout.jsx` secondary navigation.
- **Kept**: Kunnskapsbase, Bruk i AI (see product-ambiguity finding above), AI-instrukser, Enterprise Wiki (own top-level area, unchanged).
- **Old route handling**: `KnowledgeVocabularyController::storeBatch()` no longer creates a batch. It performs its existing authorization/customer-resolution side effects (so access control is unchanged), then returns a redirect to the Standardvokabular index with a flashed `error` message (`procynia.ai.knowledge_vocabulary_deprecated_batch_message`) rendered by the existing global Inertia flash banner in `CustomerAppLayout.jsx`. Never a 500. The original logic is preserved verbatim as `private function legacyStoreBatch()` (unused, undeleted) so re-enabling is a one-line change, not a rebuild. All other Standardvokabular routes (index, term/suggestion management) are untouched and fully functional — existing approved terms and pending suggestions remain viewable and actionable, consistent with "ikke slett tabeller eller data nå."
- **Frontend**: `KnowledgeVocabulary/Index.jsx`'s "Ny vokabularanalyse" document-picker form was replaced with a deprecation notice (`knowledge_vocabulary_deprecated_kicker`/`_title`/`_notice` lang keys) pointing users at Enterprise Wiki. The rest of the page (approved terms, suggestions review/approve/merge, batch history) is unchanged.

## Later deletion (not part of this phase)

Once Kunnskapsbase is superseded by a Fase 10 decision (separate ADR), the following become candidates for actual deletion in a future, dedicated task: `knowledge_metadata_terms`, `knowledge_metadata_term_suggestions`, `knowledge_vocabulary_analysis_batches` tables and their controller/routes/frontend; and, only after Fase 10 replaces `RequirementAnswerDraftService`, the Kunnskapsbase tables themselves and `KnowledgeBaseAiUsageController`. None of this is deleted now.
