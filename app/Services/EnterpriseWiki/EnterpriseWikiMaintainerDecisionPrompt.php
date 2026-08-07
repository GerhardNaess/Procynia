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
                        'no_action_reason' => ['type' => ['string', 'null']],
                        'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => [
                        'source_article',
                        'source_summary',
                        'concept_candidates',
                        'concept_pages',
                        'entity_pages',
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
                        'concept_candidate_mentions' => ['type' => 'array', 'items' => $mentionSchema],
                        'no_action_reason' => ['type' => ['string', 'null']],
                        'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => [
                        'source_article',
                        'source_summary',
                        'entity_pages',
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
                    ],
                    'required' => ['concept_candidates', 'concept_pages'],
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
            ],
            'additionalProperties' => false,
        ];
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

        return $errors;
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
