# AI answer engine consolidation — Enterprise Wiki as the sole answer engine

Branch: `refactor/ai-to-wiki-consolidation` (continuation of the AI-to-Wiki consolidation work in `docs/ai-wiki-consolidation-analysis.md`). Product direction confirmed: **the Enterprise Wiki answer engine becomes the only answer engine in "I arbeid"** — the Knowledge Base-grounded `RequirementAnswerDraftService` flow is retired from the UI and deprecated at the route level, not rebuilt to read from Wiki.

## Phase 1 — comparing the two operative flows (as they existed before this phase)

### Svarutkast (legacy, Knowledge Base-grounded)

- **Trigger**: card-level "Lag svar" button and a per-requirement prompt editor → `POST /ai/{savedNotice}/requirements/{requirement}/answer-draft` → `AiController::generateRequirementAnswerDraft()`.
- **Pipeline**: `retrievedKnowledgeChunksForRequirement()` (metadata + semantic retrieval over `KnowledgeItemChunk`) → `calculateKnowledgeGroundingLevel()` (red/yellow/green pre-check, blocks generation entirely on red) → `RequirementGroundingJudgeService::judge()` (supported/partial/unsupported, blocks on unsupported) → `RequirementAnswerDraftService::ensureAnswerDraft()` (one or several OpenAI calls, long-form sectioned for ≥800 words).
- **Storage**: columns directly on `saved_notice_ai_requirements` (`answer_draft_text`, `answer_draft_generated_at`, `answer_draft_retrieval_sources`, `answer_draft_coverage`) — no dedicated table, no version history, overwritten on every generate/save.
- **Editable**: yes, via a `<textarea>` and `PATCH /answer-draft` → `RequirementAnswerDraftService::updateAnswerDraft()`.
- **Per-requirement custom prompt**: yes (`user_answer_prompt`, validated max 5000 chars, injected as a subordinate instruction layer after case-wide `ai_instructions`).
- **Coverage UX**: a blocked "missing knowledge" card naming a recommended document to upload — inherently Knowledge-Base-specific, contrary to the product direction of retiring that dependency.
- **Export**: `RequirementWordExportService` read `answer_draft_text` + `answer_draft_retrieval_sources` directly.
- **Error handling**: an uncaught `RuntimeException` from the service reached Laravel's default handler (effectively a 500) — not a deliberate, controlled response.

### Wiki-svar (Enterprise Wiki-grounded, "Fase 9")

- **Trigger**: a separate "Lag Wiki-svar"/"Generer på nytt" button inside its own tab → `POST /ai/{savedNotice}/requirements/{requirement}/wiki-answer` → `AiController::generateRequirementWikiAnswer()`.
- **Pipeline**: `RequirementWikiResearchService` (search → read → navigate, bounded) → `RequirementWikiAnswerAiClient` (writes sections, can supplement with best practice) → `RequirementWikiAlignmentAiClient` (aligned/partially_aligned/best_practice/possible_conflict per section) → at most one automatic `RequirementWikiAnswerRevisionAiClient` pass for conflicted sections → deterministic `coverage_status` (full/partial/none) computed from alignment, never self-reported.
- **Storage**: its own table, `saved_notice_ai_requirement_wiki_answers` (`SavedNoticeAiRequirementWikiAnswer`), upserted by requirement id.
- **Editable**: no (static text, no PATCH route) — the one real functional gap versus Svarutkast.
- **Per-requirement custom prompt**: no — the other real functional gap.
- **Coverage UX**: `none` coverage is a normal, fully usable best-practice answer, not a blocked state — already the correct behavior for a Kunnskapsbase-independent engine, nothing to change here.
- **Export**: not wired in at all.
- **Error handling**: already a controlled try/catch → 422 with a fixed message — already better than the legacy flow.

## Feature matrix

| Funksjon | Svarutkast | Wiki-svar | Må beholdes | Gap i Wiki | Tiltak |
|---|---|---|---|---|---|
| Generering per krav | Ja | Ja | Ja | Nei | Wiki sin generate-rute blir eneste inngang |
| Batchgenerering | Nei | Nei | Nei | — | Ingen endring (finnes ikke i noen av flytene) |
| Redigering | Ja (textarea + PATCH) | Nei | Ja | **Ja** | Ny `updateAnswerText()` + `PATCH .../wiki-answer` |
| Autosave | Nei (manuell lagre-knapp) | N/A | Nei | — | Ingen endring — manuelt lagre-mønster videreført |
| Revisjoner (brukersynlig historikk) | Nei | Nei (kun ett internt AI-revisjonspass) | Nei | Nei | Ingen endring |
| Kildevisning / sitater | Retrieval sources (bilder/tabeller) | Sider m/tittel, discovery, kilde/beste-praksis-merking | Ja | Nei — Wiki rikere | Behold Wiki sin kildevisning |
| Dekningsstatus | Judge-basert, blokkerer på rødt | Deterministisk full/partial/none | Ja | Nei — Wiki bedre | Behold Wiki; drop KB-spesifikk blokkering |
| Ansvarlig / godkjenning | Krav-nivå (delt) | Krav-nivå (delt) | Ja | Nei | Ingen endring |
| Feil-/retry-håndtering | Ufanget exception → 500 | try/catch → 422 | Ja | Nei — Wiki bedre | Behold Wiki |
| Locale | `resolveLanguageCode()` | Identisk | Ja | Nei | Ingen endring |
| AI-instrukser (sakens ai_instructions) | Ja | Ja (tidligere fase) | Ja | Nei | Allerede lukket |
| Per-krav egendefinert prompt | Ja | Nei | Ja | **Ja** | `requirementUserPrompt` lagt til `generate()`/`generateAnswer()` |
| Eksport (Word) | Ja | Nei | Ja | **Ja** | Eksport og `RequirementWordExportService` leser nå Wiki-svar |
| Autorisasjon / kundescope | `visibleAiSavedNotice` + `assertAiAccess` | Samme mønster | Ja | Nei | Ingen endring |

Three real gaps closed: **editability + save**, **per-requirement custom prompt**, **Word export**. Everything else Wiki already matched or exceeded — copied nothing uncritically from the legacy architecture (no grounding judge, no KB-specific blocking UX, no answer-basis-item selection UI).

## Target flow (implemented)

1. User selects a requirement in "I arbeid".
2. User presses "Lag svar" (card-level or in-panel).
3. `AiController::generateRequirementWikiAnswer()` → `RequirementWikiAnswerService::generate()` — Wiki-only, no Kunnskapsbase involvement.
4. Approved Wiki pages are the knowledge basis; `ai_instructions` (case-wide) and the requirement's own one-off prompt both apply as subordinate style directives, in that priority order.
5. The answer is saved to `saved_notice_ai_requirement_wiki_answers`.
6. The user reads, edits (textarea + "Lagre endring" → `PATCH .../wiki-answer`), and works with the answer directly in the same panel.
7. Sources, citations, alignment, and coverage are shown alongside the text — no separate tab to reconcile.
8. There is no second engine left in the UI for the same task — the "Svarutkast"/"Wiki-svar" tab split is gone; one panel, one button, one save action.

## What was intentionally NOT ported from the legacy flow

- The grounding-judge red/yellow/green pre-check and its "missing knowledge, upload this document" blocked state — Kunnskapsbase-specific, contrary to the product direction; Wiki's own best-practice fallback for `none` coverage already serves the same purpose without that dependency.
- The manual answer-basis-item selection UI (choosing which Knowledge Base chunks feed generation) — Wiki auto-discovers pages via search/navigation, so this configuration step has no equivalent need.
- Long-form sectioned generation for very long target word counts — not requested as a gap; Wiki's section-based answer already scales section count to content.

## Legacy path: deprecated, not deleted

`RequirementAnswerDraftService` and the `answer-draft` generate/update routes are kept but disconnected: the controller methods now perform only their authorization/customer-resolution side effects and return a controlled `410 Gone` — never a 500. The original logic is preserved verbatim as `legacyGenerateRequirementAnswerDraft()`/`legacyUpdateRequirementAnswerDraft()` (unused, undeleted). No tables, columns, or data are touched.

## AI menu: Kunnskapsbase / Bruk i AI status unchanged in this phase

Removing the legacy answer-draft engine's only remaining production call site does **not** make Kunnskapsbase itself consumer-free: `RequirementAssessmentService` (the separate "Vurdering"/assessment feature, unrelated to answer generation) still actively reads `SavedNoticeAiEvidence` → `KnowledgeItem`/`KnowledgeItemChunk` data. The task's own Phase 6 condition — remove Kunnskapsbase/Bruk i AI from the AI menu "når gammel svarmotor ikke lenger har aktive konsumenter" — is about the **answer engine specifically**, and that condition is now met; but Kunnskapsbase as an area still has a real, separate active consumer. Per the same reasoning applied in the prior AI-to-Wiki consolidation phase, the menu entries are left visible and this is documented rather than silently resolved either way.
