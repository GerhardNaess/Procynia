<?php

namespace App\Services\EnterpriseWiki;

/**
 * Defines the strict JSON contract for the Karpathy-style maintainer decision step.
 *
 * A maintainer decision is produced by an AI pass that reads:
 *  - the source document text
 *  - the existing wiki page index (from EnterpriseWikiIndexContextService)
 *
 * It decides what pages to create or update, but does NOT generate page content.
 * Content generation happens in a separate downstream step.
 *
 * Rules encoded here (used in both the JSON schema and the PHP validator):
 *  - source_article and source_summary are 1-to-1 with the source document.
 *  - concept/entity pages are shared across sources and carry a nullable page_id.
 *  - update action on a shared page requires a non-null page_id pointing to an existing page.
 *  - proposed_slug must not contain a file extension.
 *  - title must not be a raw filename (e.g. "Masterdata.pdf").
 *  - output is a decision only — no article content, no OpenAI calls in this class.
 *
 * owned_topics/reference_only_topics/excluded_topics/related_page_guidance (added to reduce
 * cross-page repetition AND unbounded page breadth — see docs/enterprise-llm-wiki-plan.md, the
 * section on page responsibility): the maintainer sees every planned page for this source
 * document in one decision, so it is the one place that can assign non-overlapping, deliberately
 * NARROW faglig ansvar between them before any page content is generated. Three tiers, not two:
 *   - owned_topics: what this page explains in depth — its full scope, nothing beyond it.
 *   - reference_only_topics: topics this page may mention briefly (a short sentence + a link),
 *     never explain.
 *   - excluded_topics: topics this page must never mention at all, typically because they are a
 *     wider domain area the source document does not itself require (e.g. a full KPI catalog, an
 *     adjacent process area) — the deliberate mechanism against a concept/entity page silently
 *     growing into "a complete textbook page" merely because the topic is related.
 * A superseded, two-tier predecessor of this (content_responsibility/must_not_repeat) existed
 * briefly and is replaced here — see the git history for that iteration.
 * Required in the OpenAI strict JSON schema (every property must be, per the API's own
 * strict-mode constraint — see the existing concept_pages/entity_pages/warnings top-level fields
 * for the same pattern), but treated as OPTIONAL by the PHP validator/parser, defaulting to an
 * empty list when absent — exactly like every other optional top-level field in this contract —
 * so a hand-built decision (tests, or a legacy stored run predating this field) remains valid.
 *
 * planned_figures (Wiki run-587: 4 of 4 professionally significant figures were extracted,
 * classified, and made citable, but never once cited by any page — the maintainer decision itself
 * had no concept of figures existing at all): a page's own list of source-document figures it is
 * responsible for materializing, each naming the figure's real source_element_key (from
 * EnterpriseWikiDocumentSourceElementService — never invented), a classification, an optional
 * section_placement (matched against this page's own owned_topics/headings), a short purpose, a
 * required/optional flag, and an optional caption_hint. Same optional-in-PHP treatment as the
 * other responsibility fields above — a decision predating this field parses with an empty list.
 *
 * concept_candidates (added to stabilise whether a central, explicitly-referenced concept gets a
 * page — see the Wiki run-581 "ITIL Incident Management" incident: concept_pages came back empty
 * even though the article/summary both pointed the reader onward to that concept): before
 * finalising concept_pages, the maintainer must enumerate every central concept candidate it
 * considered and record one explicit, structural decision per candidate (create/reuse/
 * reference_only/exclude) plus why. This turns "the AI silently didn't propose a page" into a
 * checkable claim — EnterpriseWikiMaintainerDecisionConsistencyValidator cross-checks these
 * candidates (and related_page_guidance) against concept_pages/entity_pages/the existing wiki
 * index and flags the specific self-contradiction where a concept is necessary for the article but
 * neither created, reused, nor given an owning page. Same optional-in-PHP treatment as above.
 *
 * has_separate_source_evidence / has_reuse_value (added against overfragmentation — see the Wiki
 * "short ITIL document exploded into 9 pages" incident: a short document whose practices are each
 * mentioned only briefly under one overarching framework produced six thin standalone concept
 * pages that should have been sections of that framework page instead): a "create" decision is
 * only structurally sound when the candidate has its own separate, substantial source basis (not
 * just a brief mention alongside sibling candidates) AND independent reuse value from other,
 * unrelated wiki pages — EnterpriseWikiMaintainerDecisionHierarchyValidator enforces this
 * deterministically on the merged decision, flagging any "create" candidate that itself reports
 * either flag false. Same optional-in-PHP treatment as every other responsibility field above —
 * both default to true (structurally sound) when absent, so a legacy decision predating these
 * fields is never retroactively flagged.
 */
class EnterpriseWikiMaintainerDecisionPrompt
{
    public const ACTIONS = ['create', 'update'];

    public const CONCEPT_CANDIDATE_DECISIONS = ['create', 'reuse', 'reference_only', 'exclude'];

    /**
     * Fase 8K-2 — what a patch target does to the existing substance it names.
     *
     *  - replace:  the existing substance is expressly superseded by the new source document and
     *              must be replaced. Requires superseded_substance AND replacement_substance.
     *              `superseded_substance` is an EXACT, VERBATIM SUBSTRING of the target area's
     *              current text — see the field notes on patchTargetSchema().
     *  - amend:    the existing topic stands, but is extended or made more precise. Requires
     *              replacement_substance (the addition); superseded_substance stays null.
     *  - preserve: the page was considered against the new document and must be left UNTOUCHED.
     *              Not decoration: it is the difference between "candidate examined and
     *              deliberately not changed" and "candidate never examined", which is exactly the
     *              information 8K-3 needs in order to know that leaving a page alone was a
     *              decision rather than an omission. Carries no substance fields.
     */
    public const PATCH_OPERATIONS = ['replace', 'amend', 'preserve'];

    /**
     * Fase 8K-2 — how new substance relates to an existing canonical topic. These are the five
     * categories of the plan's «Canonical page granularity» rule (A–E), as short machine-readable
     * values:
     *
     *  A  substance_changed      existing substance is changed          -> patch the existing page
     *  B  topic_extended         same canonical topic, new substance    -> amend the existing page
     *  C  topic_specialized      variant/sub-topic of existing topic    -> amend the existing page
     *  D  reference_only         mentioned, owns no new substance       -> reuse/reference, no page
     *  E  independent_new_topic  genuinely new AND self-standing        -> create MAY be allowed
     *
     * The whole point of the enum is that `new` alone never justifies a new canonical page — only
     * E does, and only E passes the create-gate (see EnterpriseWikiCanonicalOwnershipValidator).
     */
    public const TOPIC_RELATIONSHIPS = [
        'substance_changed',
        'topic_extended',
        'topic_specialized',
        'reference_only',
        'independent_new_topic',
    ];

    /**
     * Relationships that describe an EXISTING page being affected, and are therefore the only ones
     * a patch target may carry. `independent_new_topic` is never valid on a patch target: it means
     * "this belongs on a new page", which is a create decision, not a patch.
     */
    public const PATCH_TARGET_RELATIONSHIPS = [
        'substance_changed',
        'topic_extended',
        'topic_specialized',
        'reference_only',
    ];

    /**
     * Relationships that mean an existing canonical page already owns this substance, so a new
     * canonical page must NOT be created for it.
     */
    public const EXISTING_OWNER_RELATIONSHIPS = [
        'substance_changed',
        'topic_extended',
        'topic_specialized',
    ];

    private const FILE_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'txt', 'doc', 'pptx', 'odt', 'csv'];

    /**
     * Returns the OpenAI Responses API text.format block for strict JSON output.
     * Use as: ['text' => self::jsonSchema()] in the API request body.
     */
    public static function jsonSchema(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'maintainer_decision',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'source_article' => self::sourcePageSchema(),
                        'source_summary' => self::sourcePageSchema(),
                        'concept_candidates' => ['type' => 'array', 'items' => self::conceptCandidateSchema()],
                        'concept_pages' => ['type' => 'array', 'items' => self::sharedPageSchema()],
                        'entity_pages' => ['type' => 'array', 'items' => self::sharedPageSchema()],
                        'patch_targets' => ['type' => 'array', 'items' => self::patchTargetSchema()],
                        'no_action_reason' => ['type' => ['string', 'null']],
                        'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => [
                        'source_article',
                        'source_summary',
                        'concept_candidates',
                        'concept_pages',
                        'entity_pages',
                        'patch_targets',
                        'no_action_reason',
                        'warnings',
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Phase A of the split flow (see EnterpriseWikiMaintainerDecisionSplitCoordinator): a compact
     * schema deciding source_article/source_summary/entity_pages exactly as jsonSchema() does,
     * plus concept_candidate_mentions — a deliberately minimal identification-only list (name,
     * concept_type, where mentioned) with none of the full concept_candidates disposition fields
     * (decision/justification/owning_page_title/necessary_for_article). Those are decided per
     * batch in Phase B (see candidateBatchSchema()) so this call's own output stays small
     * regardless of how many candidates the source document turns out to have.
     */
    public static function globalPlanSchema(): array
    {
        $mentionSchema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'concept_type' => ['type' => 'string'],
                'mentioned_context' => ['type' => 'string'],
            ],
            'required' => ['name', 'concept_type', 'mentioned_context'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'maintainer_decision_global_plan',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'source_article' => self::sourcePageSchema(),
                        'source_summary' => self::sourcePageSchema(),
                        'entity_pages' => ['type' => 'array', 'items' => self::sharedPageSchema()],
                        'patch_targets' => ['type' => 'array', 'items' => self::patchTargetSchema()],
                        'concept_candidate_mentions' => ['type' => 'array', 'items' => $mentionSchema],
                        'no_action_reason' => ['type' => ['string', 'null']],
                        'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => [
                        'source_article',
                        'source_summary',
                        'entity_pages',
                        'patch_targets',
                        'concept_candidate_mentions',
                        'no_action_reason',
                        'warnings',
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Phase B of the split flow: one batch's disposition for its own subset of candidates —
     * reuses the exact same concept_candidates/concept_pages fragments jsonSchema() uses, so a
     * batch response is structurally identical to that slice of a normal single-call decision.
     */
    public static function candidateBatchSchema(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'maintainer_decision_candidate_batch',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'concept_candidates' => ['type' => 'array', 'items' => self::conceptCandidateSchema()],
                        'concept_pages' => ['type' => 'array', 'items' => self::sharedPageSchema()],
                        // A batch can discover that one of ITS candidates changes substance an
                        // existing page owns. Phase A cannot know that (it never evaluates
                        // candidate disposition), so a batch must be able to contribute its own
                        // patch targets — otherwise the create-gate's "substance_changed must
                        // produce a structured target" rule would be unsatisfiable in the split
                        // flow, and the maintainer would be pushed back into free-text warnings.
                        // EnterpriseWikiMaintainerDecisionMerger unions these with Phase A's.
                        'patch_targets' => ['type' => 'array', 'items' => self::patchTargetSchema()],
                    ],
                    'required' => ['concept_candidates', 'concept_pages', 'patch_targets'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function responsibilityProperties(): array
    {
        return [
            'owned_topics' => ['type' => 'array', 'items' => ['type' => 'string']],
            'reference_only_topics' => ['type' => 'array', 'items' => ['type' => 'string']],
            'excluded_topics' => ['type' => 'array', 'items' => ['type' => 'string']],
            'related_page_guidance' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'page_title' => ['type' => 'string'],
                        'relationship' => ['type' => 'string'],
                    ],
                    'required' => ['page_title', 'relationship'],
                    'additionalProperties' => false,
                ],
            ],
            'planned_figures' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'source_element_key' => ['type' => 'string'],
                        'classification' => ['type' => 'string'],
                        'section_placement' => ['type' => ['string', 'null']],
                        'purpose' => ['type' => 'string'],
                        'required' => ['type' => 'boolean'],
                        'caption_hint' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['source_element_key', 'classification', 'section_placement', 'purpose', 'required', 'caption_hint'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function sourcePageSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge([
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'title' => ['type' => 'string'],
                'proposed_slug' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ], self::responsibilityProperties()),
            'required' => ['action', 'title', 'proposed_slug', 'reason', 'owned_topics', 'reference_only_topics', 'excluded_topics', 'related_page_guidance', 'planned_figures'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function sharedPageSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge([
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'page_id' => ['type' => ['integer', 'null']],
                'title' => ['type' => 'string'],
                'proposed_slug' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ], self::responsibilityProperties()),
            'required' => ['action', 'page_id', 'title', 'proposed_slug', 'reason', 'owned_topics', 'reference_only_topics', 'excluded_topics', 'related_page_guidance', 'planned_figures'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function conceptCandidateSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'concept_type' => ['type' => 'string'],
                'independent_reason' => ['type' => 'string'],
                'mentioned_context' => ['type' => 'string'],
                'existing_page_title' => ['type' => ['string', 'null']],
                'decision' => ['type' => 'string', 'enum' => self::CONCEPT_CANDIDATE_DECISIONS],
                'justification' => ['type' => 'string'],
                'owning_page_title' => ['type' => ['string', 'null']],
                'necessary_for_article' => ['type' => 'boolean'],
                'has_separate_source_evidence' => ['type' => 'boolean'],
                'has_reuse_value' => ['type' => 'boolean'],
                // Fase 8K-2 — the granularity axis. `decision` says WHAT to do with the candidate;
                // `relationship` says whether an existing canonical page already owns this
                // substance, which is what makes the create-gate decidable rather than advisory.
                'relationship' => ['type' => 'string', 'enum' => self::TOPIC_RELATIONSHIPS],
                'existing_owner_page_id' => ['type' => ['integer', 'null']],
            ],
            'required' => [
                'name',
                'concept_type',
                'independent_reason',
                'mentioned_context',
                'existing_page_title',
                'decision',
                'justification',
                'owning_page_title',
                'necessary_for_article',
                'has_separate_source_evidence',
                'has_reuse_value',
                'relationship',
                'existing_owner_page_id',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Fase 8K-2 — one existing Wiki page this source document affects.
     *
     * Deliberately a SEPARATE top-level list rather than more fields on the page-creation slots.
     * The page-creation slots are typed (`concept_pages`, `entity_pages`) and
     * `source_article`/`source_summary` carry no page_id at all, so expressing "update this
     * existing article" through them was impossible — and forcing it (putting an article into
     * `entity_pages`) would have made EnterpriseWikiMaintainerDecisionApplyService::syncReusedPage()
     * silently retype the page. A patch target names a page by id and never by slot, so it can
     * address ANY existing page type without the type ever being implied by where it was written.
     *
     * `target_page_type` is present for the model to state what it believes it is targeting; it is
     * verified against the database and never trusted (see EnterpriseWikiPatchTargetResolver). The
     * database is the only authority on a page's type, and nothing here can change it.
     *
     * `superseded_substance` is an EXACT, VERBATIM SUBSTRING of the target area's current text — not a
     * description of it, and not a paraphrase. It is the text a `replace` removes, so the patch engine
     * locates it by plain substring search and rewrites exactly those characters
     * (EnterpriseWikiPatchApplicationService); EnterpriseWikiPatchTargetResolver verifies its presence
     * at decision time so a near-miss quote becomes a repairable validation issue rather than a late
     * failure.
     *
     * It does NOT have to be a whole sentence, clause or paragraph. It has to be:
     *  - character-for-character present in the target area, punctuation included, and
     *  - specific enough to identify the substance being replaced, and unique within that area.
     *
     * A shorter exact substring is therefore perfectly valid, and often safer than a long quote: the
     * failure mode observed twice in production (runs 28 and 29) was a maintainer quoting a CLAUSE as
     * though it were a sentence — writing "… within 30 minutes." where the page says "… within 30
     * minutes, the manager shall …" — which is not a substring at all. Copying less, exactly, avoids
     * that entirely. If the same text occurs more than once in the area, the patch engine refuses it
     * as ambiguous rather than guessing, so a longer, distinguishing substring is what disambiguates.
     *
     * `preserve_topics` is TARGET-LOCAL, not page-wide. It names substance inside THIS target's own
     * section/topic area that must survive the patch — the neighbouring statements a replace or amend
     * sits next to and must not take with it. It is explicitly NOT a list of everything else on the
     * page, and a maintainer is never expected to enumerate unrelated sections in it.
     *
     * The page-wide guarantee is a separate, stronger invariant that 8K-3 owes regardless of what
     * this list says:
     *
     *     Everything outside the patch target's own bounded area is preserved BY DEFAULT.
     *
     * So the two mechanisms are: `preserve_topics` = explicit local protection inside the target
     * area; everything beyond the target area = implicit preserve. Absence of a topic from
     * `preserve_topics` is NEVER permission to delete it — it only means the maintainer saw no risk
     * of that particular statement being swept up by this specific edit.
     *
     * Section/topic identity: the Wiki has no stable section identifier today — `block_key` is
     * reallocated on every regenerated version, and `owned_topics` is free text. `target_topic` is
     * therefore a required descriptor, and `target_heading` an OPTIONAL exact heading from the
     * target page's current version, verified against it when present. That gives 8K-3 a real
     * anchor when the substance sits under a heading, and an honest descriptor when it does not.
     * Documented limitation, not an oversight: fact/span identity is explicitly out of 8K scope.
     *
     * @return array<string, mixed>
     */
    private static function patchTargetSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_page_id' => ['type' => 'integer'],
                'target_page_title' => ['type' => 'string'],
                'target_page_type' => ['type' => 'string'],
                'target_topic' => ['type' => 'string'],
                'target_heading' => ['type' => ['string', 'null']],
                'relationship' => ['type' => 'string', 'enum' => self::PATCH_TARGET_RELATIONSHIPS],
                'operation' => ['type' => 'string', 'enum' => self::PATCH_OPERATIONS],
                'superseded_substance' => ['type' => ['string', 'null']],
                'replacement_substance' => ['type' => ['string', 'null']],
                'source_element_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
                'preserve_topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                'reason' => ['type' => 'string'],
            ],
            'required' => [
                'target_page_id',
                'target_page_title',
                'target_page_type',
                'target_topic',
                'target_heading',
                'relationship',
                'operation',
                'superseded_substance',
                'replacement_substance',
                'source_element_keys',
                'preserve_topics',
                'reason',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Sentinel for a target that names no heading. A fixed marker, never a unique value: "no heading"
     * is ONE identity shared by every target that declines to name one.
     */
    public const NO_HEADING_IDENTITY = '~no-heading';

    /**
     * The semantic identity of a patch target — the single definition of "these two targets are the
     * same target". Both the validator (conflict/duplicate detection) and the merger (split-flow
     * dedupe) use this; they previously carried two subtly different keys of their own, which is
     * exactly how one rule starts meaning two things.
     *
     * Identity is (page, topic, heading) — deliberately including the heading. Run 27 showed why: an
     * existing page legitimately stated the same superseded requirement under TWO different headings
     * (a duplicated section inherited from an earlier run), so the maintainer needed two targets for
     * one topic. Keying on (page, topic) alone collapsed them into a "duplicate", and the bounded
     * repair pass dropped one — which would have left the second occurrence stale after 8K-3, with
     * the page contradicting itself.
     *
     * A null heading is a real, distinct identity, not a wildcard: it means "this topic on this page,
     * with no specific heading anchor". Two targets that both decline to name a heading for the same
     * topic ARE indistinguishable, so null maps to one fixed sentinel rather than to a unique value.
     *
     * Normalization is whitespace + case only, plus a trailing ATX "#" run on headings (matching
     * EnterpriseWikiPatchTargetResolver's own heading comparison). Punctuation is deliberately NOT
     * stripped: two headings differing only in punctuation are far more likely to be one heading
     * written twice than two genuinely different sections, and collapsing them is the safer error.
     *
     * @param  array<string, mixed>  $target
     */
    public static function patchTargetIdentity(array $target): string
    {
        $pageId = $target['target_page_id'] ?? null;
        $heading = $target['target_heading'] ?? null;

        return implode('|', [
            is_int($pageId) ? (string) $pageId : '?',
            self::normalizeIdentityPart((string) ($target['target_topic'] ?? '')),
            is_string($heading) && trim($heading) !== ''
                ? self::normalizeIdentityPart((string) (preg_replace('/\s+#+\s*$/u', '', trim($heading)) ?? $heading))
                : self::NO_HEADING_IDENTITY,
        ]);
    }

    private static function normalizeIdentityPart(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value)));
    }

    /**
     * Validate a raw decoded decision object.
     *
     * @param  array<string, mixed>  $raw
     * @return string[] Empty when valid; one string per error when invalid.
     */
    public static function validate(array $raw): array
    {
        $errors = [];

        foreach (['source_article', 'source_summary'] as $key) {
            if (! isset($raw[$key]) || ! is_array($raw[$key])) {
                $errors[] = "{$key} is required and must be an object.";

                continue;
            }
            $errors = array_merge($errors, self::validateSourceEntry($raw[$key], $key));
        }

        foreach (['concept_pages', 'entity_pages'] as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            if (! is_array($raw[$key])) {
                $errors[] = "{$key} must be an array.";

                continue;
            }
            foreach ($raw[$key] as $i => $entry) {
                $errors = array_merge($errors, self::validateSharedEntry($entry, "{$key}[{$i}]"));
            }
        }

        if (array_key_exists('concept_candidates', $raw)) {
            if (! is_array($raw['concept_candidates'])) {
                $errors[] = 'concept_candidates must be an array.';
            } else {
                foreach ($raw['concept_candidates'] as $i => $entry) {
                    $errors = array_merge($errors, self::validateConceptCandidateEntry($entry, "concept_candidates[{$i}]"));
                }
            }
        }

        $errors = array_merge($errors, self::validatePatchTargets($raw));

        if (
            array_key_exists('no_action_reason', $raw)
            && $raw['no_action_reason'] !== null
            && ! is_string($raw['no_action_reason'])
        ) {
            $errors[] = 'no_action_reason must be a string or null.';
        }

        if (array_key_exists('warnings', $raw) && ! is_array($raw['warnings'])) {
            $errors[] = 'warnings must be an array of strings.';
        }

        return $errors;
    }

    /**
     * Fase 8K-2 — schema-level patch-target validation.
     *
     * Absent `patch_targets` is valid and means "this document changes nothing existing": a
     * decision consisting only of create/reuse/reference_only entries stays valid exactly as
     * before, and a stored decision predating this field parses unchanged. Same optional-in-PHP
     * treatment as every other collection field in this contract.
     *
     * Nothing here touches the database. Target existence, customer scoping, page type and
     * availability are EnterpriseWikiPatchTargetResolver's job; cross-entry rules (create-gate,
     * duplicate ownership, conflicting operations) are EnterpriseWikiCanonicalOwnershipValidator's.
     *
     * @param  array<string, mixed>  $raw
     * @return string[]
     */
    private static function validatePatchTargets(array $raw): array
    {
        if (! array_key_exists('patch_targets', $raw)) {
            return [];
        }

        if (! is_array($raw['patch_targets'])) {
            return ['patch_targets must be an array.'];
        }

        $errors = [];

        foreach ($raw['patch_targets'] as $i => $entry) {
            $errors = array_merge($errors, self::validatePatchTargetEntry($entry, "patch_targets[{$i}]"));
        }

        return $errors;
    }

    /** @return string[] */
    private static function validatePatchTargetEntry(mixed $entry, string $ctx): array
    {
        if (! is_array($entry)) {
            return ["{$ctx} must be an object."];
        }

        $errors = [];

        if (! isset($entry['target_page_id']) || ! is_int($entry['target_page_id']) || $entry['target_page_id'] < 1) {
            $errors[] = "{$ctx}.target_page_id is required and must be a positive integer.";
        }

        foreach (['target_page_title', 'target_page_type', 'target_topic', 'reason'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $errors[] = "{$ctx}.{$field} is required and must be a non-empty string.";

                continue;
            }

            $errors = array_merge($errors, self::validateNoControlCharacters($entry[$field], "{$ctx}.{$field}"));
        }

        foreach (['target_heading', 'superseded_substance', 'replacement_substance'] as $field) {
            if (! array_key_exists($field, $entry) || $entry[$field] === null) {
                continue;
            }

            if (! is_string($entry[$field])) {
                $errors[] = "{$ctx}.{$field} must be a string or null.";

                continue;
            }

            $errors = array_merge($errors, self::validateNoControlCharacters($entry[$field], "{$ctx}.{$field}"));
        }

        if (! isset($entry['relationship']) || ! in_array($entry['relationship'], self::PATCH_TARGET_RELATIONSHIPS, true)) {
            $errors[] = "{$ctx}.relationship must be one of: ".implode(', ', self::PATCH_TARGET_RELATIONSHIPS).
                ' (independent_new_topic is never valid on a patch target — it means a new page, not a patch).';
        }

        if (! isset($entry['operation']) || ! in_array($entry['operation'], self::PATCH_OPERATIONS, true)) {
            $errors[] = "{$ctx}.operation must be one of: ".implode(', ', self::PATCH_OPERATIONS).'.';
        }

        foreach (['source_element_keys', 'preserve_topics'] as $field) {
            if (! array_key_exists($field, $entry)) {
                continue;
            }

            if (! is_array($entry[$field])) {
                $errors[] = "{$ctx}.{$field} must be an array of strings.";

                continue;
            }

            foreach ($entry[$field] as $j => $value) {
                if (! is_string($value) || trim($value) === '') {
                    $errors[] = "{$ctx}.{$field}[{$j}] must be a non-empty string.";

                    continue;
                }

                $errors = array_merge($errors, self::validateNoControlCharacters($value, "{$ctx}.{$field}[{$j}]"));
            }
        }

        return array_merge($errors, self::validatePatchTargetSemantics($entry, $ctx));
    }

    /**
     * The operation <-> substance <-> relationship coupling. This is what stops `operation` from
     * being a label: a `replace` that cannot say what it replaces, or an `amend` that cannot say
     * what it adds, gives 8K-3 nothing to act on and is rejected here rather than silently
     * degenerating into "regenerate the page from the new document", which is precisely the
     * destructive behaviour Fase 8K exists to remove.
     *
     * @param  array<string, mixed>  $entry
     * @return string[]
     */
    private static function validatePatchTargetSemantics(array $entry, string $ctx): array
    {
        $operation = $entry['operation'] ?? null;
        $relationship = $entry['relationship'] ?? null;
        $superseded = self::nonEmptyOrNull($entry['superseded_substance'] ?? null);
        $replacement = self::nonEmptyOrNull($entry['replacement_substance'] ?? null);
        $sourceKeys = array_values(array_filter(
            (array) ($entry['source_element_keys'] ?? []),
            static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));

        $errors = [];

        if ($operation === 'replace') {
            if ($superseded === null) {
                $errors[] = "{$ctx}.superseded_substance is required for operation \"replace\" — copy the exact, verbatim text being superseded from the target area.";
            }

            if ($replacement === null) {
                $errors[] = "{$ctx}.replacement_substance is required for operation \"replace\" — state the substance that takes its place.";
            }

            if ($relationship !== null && $relationship !== 'substance_changed') {
                $errors[] = "{$ctx}.operation \"replace\" requires relationship \"substance_changed\", got \"{$relationship}\".";
            }
        }

        if ($operation === 'amend') {
            if ($replacement === null) {
                $errors[] = "{$ctx}.replacement_substance is required for operation \"amend\" — state the substance being added or made more precise.";
            }

            if ($relationship !== null && ! in_array($relationship, ['topic_extended', 'topic_specialized'], true)) {
                $errors[] = "{$ctx}.operation \"amend\" requires relationship \"topic_extended\" or \"topic_specialized\", got \"{$relationship}\".";
            }
        }

        if ($operation === 'preserve') {
            if ($superseded !== null || $replacement !== null) {
                $errors[] = "{$ctx}.operation \"preserve\" must not carry superseded_substance or replacement_substance — it asserts that this page is left untouched.";
            }

            if ($sourceKeys !== []) {
                $errors[] = "{$ctx}.source_element_keys must be empty for operation \"preserve\" — nothing in the source authorises a change to this page.";
            }

            if ($relationship !== null && $relationship !== 'reference_only') {
                $errors[] = "{$ctx}.operation \"preserve\" requires relationship \"reference_only\", got \"{$relationship}\".";
            }
        }

        if (in_array($operation, ['replace', 'amend'], true) && $sourceKeys === []) {
            $errors[] = "{$ctx}.source_element_keys must name at least one source element authorising a \"{$operation}\" — a substantive change must be traceable to the source document.";
        }

        return $errors;
    }

    private static function nonEmptyOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }

    /**
     * Validate and return a normalised decision array.
     * Optional collection keys default to empty arrays/null.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when validation fails.
     */
    public static function parse(array $raw): array
    {
        $errors = self::validate($raw);

        if ($errors !== []) {
            throw new \InvalidArgumentException(
                'Invalid maintainer decision: '.implode(' | ', $errors)
            );
        }

        return [
            'source_article' => $raw['source_article'],
            'source_summary' => $raw['source_summary'],
            'concept_candidates' => $raw['concept_candidates'] ?? [],
            'concept_pages' => $raw['concept_pages'] ?? [],
            'entity_pages' => $raw['entity_pages'] ?? [],
            'patch_targets' => $raw['patch_targets'] ?? [],
            'no_action_reason' => $raw['no_action_reason'] ?? null,
            'warnings' => $raw['warnings'] ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // Split flow — Phase A (global plan)
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $raw
     * @return string[]
     */
    public static function validateGlobalPlan(array $raw): array
    {
        $errors = [];

        foreach (['source_article', 'source_summary'] as $key) {
            if (! isset($raw[$key]) || ! is_array($raw[$key])) {
                $errors[] = "{$key} is required and must be an object.";

                continue;
            }
            $errors = array_merge($errors, self::validateSourceEntry($raw[$key], $key));
        }

        if (array_key_exists('entity_pages', $raw)) {
            if (! is_array($raw['entity_pages'])) {
                $errors[] = 'entity_pages must be an array.';
            } else {
                foreach ($raw['entity_pages'] as $i => $entry) {
                    $errors = array_merge($errors, self::validateSharedEntry($entry, "entity_pages[{$i}]"));
                }
            }
        }

        if (array_key_exists('concept_candidate_mentions', $raw)) {
            if (! is_array($raw['concept_candidate_mentions'])) {
                $errors[] = 'concept_candidate_mentions must be an array.';
            } else {
                foreach ($raw['concept_candidate_mentions'] as $i => $entry) {
                    $errors = array_merge($errors, self::validateMentionEntry($entry, "concept_candidate_mentions[{$i}]"));
                }
            }
        }

        $errors = array_merge($errors, self::validatePatchTargets($raw));

        if (
            array_key_exists('no_action_reason', $raw)
            && $raw['no_action_reason'] !== null
            && ! is_string($raw['no_action_reason'])
        ) {
            $errors[] = 'no_action_reason must be a string or null.';
        }

        if (array_key_exists('warnings', $raw) && ! is_array($raw['warnings'])) {
            $errors[] = 'warnings must be an array of strings.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when validation fails.
     */
    public static function parseGlobalPlan(array $raw): array
    {
        $errors = self::validateGlobalPlan($raw);

        if ($errors !== []) {
            throw new \InvalidArgumentException(
                'Invalid maintainer decision global plan: '.implode(' | ', $errors)
            );
        }

        return [
            'source_article' => $raw['source_article'],
            'source_summary' => $raw['source_summary'],
            'entity_pages' => $raw['entity_pages'] ?? [],
            'patch_targets' => $raw['patch_targets'] ?? [],
            'concept_candidate_mentions' => $raw['concept_candidate_mentions'] ?? [],
            'no_action_reason' => $raw['no_action_reason'] ?? null,
            'warnings' => $raw['warnings'] ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // Split flow — Phase B (candidate batch)
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $raw
     * @return string[]
     */
    public static function validateCandidateBatch(array $raw): array
    {
        $errors = [];

        if (! array_key_exists('concept_candidates', $raw) || ! is_array($raw['concept_candidates'])) {
            $errors[] = 'concept_candidates is required and must be an array.';
        } else {
            foreach ($raw['concept_candidates'] as $i => $entry) {
                $errors = array_merge($errors, self::validateConceptCandidateEntry($entry, "concept_candidates[{$i}]"));
            }
        }

        if (! array_key_exists('concept_pages', $raw) || ! is_array($raw['concept_pages'])) {
            $errors[] = 'concept_pages is required and must be an array.';
        } else {
            foreach ($raw['concept_pages'] as $i => $entry) {
                $errors = array_merge($errors, self::validateSharedEntry($entry, "concept_pages[{$i}]"));
            }
        }

        return array_merge($errors, self::validatePatchTargets($raw));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when validation fails.
     */
    public static function parseCandidateBatch(array $raw): array
    {
        $errors = self::validateCandidateBatch($raw);

        if ($errors !== []) {
            throw new \InvalidArgumentException(
                'Invalid maintainer decision candidate batch: '.implode(' | ', $errors)
            );
        }

        return [
            'concept_candidates' => $raw['concept_candidates'],
            'concept_pages' => $raw['concept_pages'],
            'patch_targets' => $raw['patch_targets'] ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // Internal validators
    // -------------------------------------------------------------------------

    /** @return string[] */
    private static function validateMentionEntry(mixed $entry, string $ctx): array
    {
        if (! is_array($entry)) {
            return ["{$ctx} must be an object."];
        }

        $errors = [];

        foreach (['name', 'concept_type', 'mentioned_context'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $errors[] = "{$ctx}.{$field} is required and must be a non-empty string.";

                continue;
            }

            $errors = array_merge($errors, self::validateNoControlCharacters($entry[$field], "{$ctx}.{$field}"));
        }

        return $errors;
    }

    /** @return string[] */
    private static function validateSourceEntry(array $entry, string $ctx): array
    {
        $errors = [];

        if (! isset($entry['action']) || ! in_array($entry['action'], self::ACTIONS, true)) {
            $errors[] = "{$ctx}.action must be one of: ".implode(', ', self::ACTIONS).'.';
        }

        foreach (['title', 'proposed_slug', 'reason'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $errors[] = "{$ctx}.{$field} is required and must be a non-empty string.";

                continue;
            }

            $errors = array_merge($errors, self::validateNoControlCharacters($entry[$field], "{$ctx}.{$field}"));
        }

        if (isset($entry['title']) && is_string($entry['title'])) {
            $errors = array_merge($errors, self::validateNoFileExtensionInTitle($entry['title'], "{$ctx}.title"));
        }

        if (isset($entry['proposed_slug']) && is_string($entry['proposed_slug'])) {
            $errors = array_merge($errors, self::validateNoFileExtensionInSlug($entry['proposed_slug'], "{$ctx}.proposed_slug"));
        }

        foreach (['owned_topics', 'reference_only_topics', 'excluded_topics'] as $field) {
            if (! array_key_exists($field, $entry)) {
                continue;
            }

            if (! is_array($entry[$field])) {
                $errors[] = "{$ctx}.{$field} must be an array of strings.";

                continue;
            }

            foreach ($entry[$field] as $i => $item) {
                if (! is_string($item) || trim($item) === '') {
                    $errors[] = "{$ctx}.{$field}[{$i}] must be a non-empty string.";

                    continue;
                }

                $errors = array_merge($errors, self::validateNoControlCharacters($item, "{$ctx}.{$field}[{$i}]"));
            }
        }

        if (array_key_exists('related_page_guidance', $entry)) {
            if (! is_array($entry['related_page_guidance'])) {
                $errors[] = "{$ctx}.related_page_guidance must be an array.";
            } else {
                foreach ($entry['related_page_guidance'] as $i => $item) {
                    $errors = array_merge($errors, self::validateRelatedPageGuidanceEntry($item, "{$ctx}.related_page_guidance[{$i}]"));
                }
            }
        }

        if (array_key_exists('planned_figures', $entry)) {
            if (! is_array($entry['planned_figures'])) {
                $errors[] = "{$ctx}.planned_figures must be an array.";
            } else {
                foreach ($entry['planned_figures'] as $i => $item) {
                    $errors = array_merge($errors, self::validatePlannedFigureEntry($item, "{$ctx}.planned_figures[{$i}]"));
                }
            }
        }

        return $errors;
    }

    /** @return string[] */
    private static function validateRelatedPageGuidanceEntry(mixed $entry, string $ctx): array
    {
        if (! is_array($entry)) {
            return ["{$ctx} must be an object."];
        }

        $errors = [];

        foreach (['page_title', 'relationship'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $errors[] = "{$ctx}.{$field} is required and must be a non-empty string.";

                continue;
            }

            $errors = array_merge($errors, self::validateNoControlCharacters($entry[$field], "{$ctx}.{$field}"));
        }

        return $errors;
    }

    /**
     * Schema-level validation only — this does NOT confirm source_element_key resolves to a real,
     * showable image (that requires the document's actual extracted figure inventory, which this
     * pure-schema class has no access to). That check belongs to
     * EnterpriseWikiMaintainerDecisionConsistencyValidator (given the real candidate key list) and,
     * as a final backstop, EnterpriseWikiPlannedFigureCoverageValidator at generation time.
     *
     * @return string[]
     */
    private static function validatePlannedFigureEntry(mixed $entry, string $ctx): array
    {
        if (! is_array($entry)) {
            return ["{$ctx} must be an object."];
        }

        $errors = [];

        foreach (['source_element_key', 'classification', 'purpose'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $errors[] = "{$ctx}.{$field} is required and must be a non-empty string.";

                continue;
            }

            $errors = array_merge($errors, self::validateNoControlCharacters($entry[$field], "{$ctx}.{$field}"));
        }

        foreach (['section_placement', 'caption_hint'] as $field) {
            if (array_key_exists($field, $entry) && $entry[$field] !== null && ! is_string($entry[$field])) {
                $errors[] = "{$ctx}.{$field} must be a string or null.";
            }
        }

        if (! array_key_exists('required', $entry) || ! is_bool($entry['required'])) {
            $errors[] = "{$ctx}.required is required and must be a boolean.";
        }

        return $errors;
    }

    /** @return string[] */
    private static function validateConceptCandidateEntry(mixed $entry, string $ctx): array
    {
        if (! is_array($entry)) {
            return ["{$ctx} must be an object."];
        }

        $errors = [];

        foreach (['name', 'concept_type', 'independent_reason', 'mentioned_context', 'justification'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $errors[] = "{$ctx}.{$field} is required and must be a non-empty string.";

                continue;
            }

            $errors = array_merge($errors, self::validateNoControlCharacters($entry[$field], "{$ctx}.{$field}"));
        }

        foreach (['existing_page_title', 'owning_page_title'] as $field) {
            if (array_key_exists($field, $entry) && $entry[$field] !== null && ! is_string($entry[$field])) {
                $errors[] = "{$ctx}.{$field} must be a string or null.";
            }
        }

        if (! isset($entry['decision']) || ! in_array($entry['decision'], self::CONCEPT_CANDIDATE_DECISIONS, true)) {
            $errors[] = "{$ctx}.decision must be one of: ".implode(', ', self::CONCEPT_CANDIDATE_DECISIONS).'.';
        }

        if (array_key_exists('necessary_for_article', $entry) && ! is_bool($entry['necessary_for_article'])) {
            $errors[] = "{$ctx}.necessary_for_article must be a boolean.";
        }

        foreach (['has_separate_source_evidence', 'has_reuse_value'] as $field) {
            if (array_key_exists($field, $entry) && ! is_bool($entry[$field])) {
                $errors[] = "{$ctx}.{$field} must be a boolean.";
            }
        }

        // Fase 8K-2 granularity fields. Optional in PHP exactly like every other responsibility
        // field in this contract, so a stored decision predating them still parses; the strict
        // JSON schema requires them, so every newly generated decision carries them.
        if (array_key_exists('relationship', $entry) && ! in_array($entry['relationship'], self::TOPIC_RELATIONSHIPS, true)) {
            $errors[] = "{$ctx}.relationship must be one of: ".implode(', ', self::TOPIC_RELATIONSHIPS).'.';
        }

        if (
            array_key_exists('existing_owner_page_id', $entry)
            && $entry['existing_owner_page_id'] !== null
            && (! is_int($entry['existing_owner_page_id']) || $entry['existing_owner_page_id'] < 1)
        ) {
            $errors[] = "{$ctx}.existing_owner_page_id must be a positive integer or null.";
        }

        return $errors;
    }

    /** @return string[] */
    private static function validateSharedEntry(mixed $entry, string $ctx): array
    {
        if (! is_array($entry)) {
            return ["{$ctx} must be an object."];
        }

        $errors = self::validateSourceEntry($entry, $ctx);

        if (! array_key_exists('page_id', $entry)) {
            $errors[] = "{$ctx}.page_id is required (null for create, integer for update).";
        } elseif ($entry['page_id'] !== null && ! is_int($entry['page_id'])) {
            $errors[] = "{$ctx}.page_id must be an integer or null.";
        }

        if (
            isset($entry['action']) && $entry['action'] === 'update'
            && (! isset($entry['page_id']) || ! is_int($entry['page_id']))
        ) {
            $errors[] = "{$ctx}.page_id must be a non-null integer for update action.";
        }

        return $errors;
    }

    /** @return string[] */
    private static function validateNoFileExtensionInTitle(string $title, string $ctx): array
    {
        $ext = mb_strtolower((string) pathinfo($title, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::FILE_EXTENSIONS, true)) {
            return ["{$ctx} must not be a raw filename — strip the file extension (found: .{$ext})."];
        }

        return [];
    }

    /** @return string[] */
    private static function validateNoFileExtensionInSlug(string $slug, string $ctx): array
    {
        $extPattern = implode('|', self::FILE_EXTENSIONS);
        if (preg_match('/[-.](?:'.$extPattern.')$/i', $slug)) {
            return ["{$ctx} must not contain a file extension — remove it from the slug."];
        }

        return [];
    }

    /**
     * Rejects a maintainer-decision text field that is not valid UTF-8, or that contains a raw
     * ASCII control character (anything other than the ordinary whitespace/newline characters).
     *
     * Found via the Wiki run-34 investigation: an AI-generated title was persisted verbatim
     * containing literal control bytes (a stray low ASCII control byte, and a malformed Unicode
     * escape sequence, in the stored JSON) where a Norwegian letter with a diacritic should have
     * been — corrupting the page title. The corruption traced back to the model's own structured-
     * output text, not to any byte-unsafe truncation in this codebase
     * (EnterpriseWikiMaintainerDecisionApplyService persists title/proposed_slug/reason verbatim
     * from here). Rejecting it here, at the same validation boundary that already rejects a raw
     * filename-as-title, fails the maintainer decision step loudly and traceably instead of
     * silently storing corrupted text as a page title.
     *
     * @return string[]
     */
    private static function validateNoControlCharacters(string $value, string $ctx): array
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return ["{$ctx} is not valid UTF-8 — the AI response text is corrupted."];
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            return ["{$ctx} contains an invalid control character — the AI response text is corrupted."];
        }

        return [];
    }
}
