<?php

namespace App\Services\EnterpriseWiki;

use InvalidArgumentException;

/**
 * The contract for a BOUNDED, DELTA-shaped maintainer-decision repair.
 *
 * The predecessor of this contract asked the model to return the complete corrected decision, in
 * the full EnterpriseWikiMaintainerDecisionPrompt schema, for every repair — however small the
 * actual fault. That is unsatisfiable by construction for any decision large enough to have needed
 * the split flow in the first place: run 51's decision was 31 599 characters (~9 500 output tokens)
 * against a 9 000-token ceiling, and 65 % of what the repair had to re-emit was content the
 * deterministic validators had already accepted. It failed, twice, at exactly the ceiling.
 *
 * Here the model returns only what changes:
 *
 *   - `replace` — this object, named by its object id, in its corrected form.
 *   - `remove`  — this object should not be part of the decision at all.
 *   - `add`     — an object the fix requires that does not exist yet (typically the concept page a
 *                 "create" candidate was missing, or a patch target for an existing owner).
 *
 * Output size therefore scales with the number of FAULTS, not with the size of the decision, which
 * is what turns max_output_tokens back into a safety bound instead of a hidden ceiling on how
 * complex a source document Procynia can plan for.
 *
 * Object ids are the ones EnterpriseWikiMaintainerDecisionObjectIndex assigns — the same tokens the
 * validators already print in their issue messages. Nothing here trusts them: every id is resolved
 * against the pre-repair snapshot, and against the specific group of objects this call was given,
 * by EnterpriseWikiMaintainerDecisionDeltaMerger, which rejects anything else fail-closed.
 */
class EnterpriseWikiMaintainerDecisionDeltaPrompt
{
    public const OPERATION_REPLACE = 'replace';

    public const OPERATION_ADD = 'add';

    public const OPERATION_REMOVE = 'remove';

    public const OPERATIONS = [self::OPERATION_REPLACE, self::OPERATION_ADD, self::OPERATION_REMOVE];

    /**
     * Delta list name => the decision collection it edits. `source_page_repairs` is separate
     * because source_article/source_summary are single slots: they can be corrected, never added
     * or removed — a source document always has exactly one article and one summary.
     */
    public const REPAIR_LISTS = [
        'concept_candidate_repairs' => EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_CONCEPT_CANDIDATES,
        'concept_page_repairs' => EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_CONCEPT_PAGES,
        'entity_page_repairs' => EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_ENTITY_PAGES,
        'patch_target_repairs' => EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_PATCH_TARGETS,
    ];

    public const SOURCE_PAGE_LIST = 'source_page_repairs';

    /** Returns the OpenAI Responses API text.format block for the delta contract. */
    public static function jsonSchema(): array
    {
        $properties = [];

        foreach (self::REPAIR_LISTS as $list => $collection) {
            $properties[$list] = [
                'type' => 'array',
                'items' => self::repairEntrySchema(match ($collection) {
                    EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_CONCEPT_CANDIDATES => EnterpriseWikiMaintainerDecisionPrompt::conceptCandidateObjectSchema(),
                    EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_PATCH_TARGETS => EnterpriseWikiMaintainerDecisionPrompt::patchTargetObjectSchema(),
                    default => EnterpriseWikiMaintainerDecisionPrompt::sharedPageObjectSchema(),
                }),
            ];
        }

        $properties[self::SOURCE_PAGE_LIST] = [
            'type' => 'array',
            'items' => self::repairEntrySchema(EnterpriseWikiMaintainerDecisionPrompt::sourcePageObjectSchema()),
        ];

        $properties['notes'] = ['type' => ['string', 'null']];

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'maintainer_decision_repair_delta',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => array_keys($properties),
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $objectSchema */
    private static function repairEntrySchema(array $objectSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'object_id' => ['type' => ['string', 'null']],
                'operation' => ['type' => 'string', 'enum' => self::OPERATIONS],
                'object' => array_merge($objectSchema, ['type' => ['object', 'null']]),
            ],
            'required' => ['object_id', 'operation', 'object'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The developer prompt: the same domain resolution rules the whole-decision repair used
     * (EnterpriseWikiMaintainerDecisionAiClient::repairResolutionRules()), plus the delta-output
     * mechanics that replace "return the complete corrected decision".
     */
    public static function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            "You are an enterprise wiki maintainer correcting a previous planning decision. Output language: {$languageName}.",
            'A deterministic check found specific faults in a FEW objects of that decision. You are shown',
            'only those objects, under OBJECTS TO REPAIR, each with an object_id.',
            '',
            'Return a DELTA — only what changes. Never restate the decision, and never return an object',
            'you were not shown. Everything you do not mention is kept exactly as it is.',
            '',
            'Each repair entry has object_id, operation and object:',
            '  operation "replace": object_id = the object you are correcting, object = its complete,',
            '  corrected form (every field of that object, not just the changed ones).',
            '  operation "remove": object_id = the object that should not be part of the decision at',
            '  all, object = null.',
            '  operation "add": object = a NEW object the fix requires (typically the concept page a',
            '  "create" candidate was missing, or a patch target for an existing owner), object_id = null.',
            'Put each entry in the list matching its kind: concept_candidate_repairs, concept_page_repairs,',
            'entity_page_repairs, patch_target_repairs, source_page_repairs. For source_page_repairs the',
            'object_id is "source_article" or "source_summary"; those two can only be replaced.',
            'An object_id you were not given will be rejected and the whole run fails — if a fix seems to',
            'require changing an object you cannot see, choose a resolution inside the objects you were',
            'given instead.',
            '',
            'Fix ONLY the listed issues. Keep every other field of a corrected object as it was: same',
            'titles, slugs, actions and wording unless the issue requires otherwise.',
            '',
            'PAGES PLANNED IN THIS DECISION lists every page this decision already creates or updates.',
            'Those are valid owning pages, together with the pages in the wiki index. The source article',
            'and source summary are NOT valid owning pages for a concept candidate — they describe the',
            'document, not the subject matter. A topic that only this document explains and that no',
            'concept/entity page owns is decided "exclude", never pointed at the article.',
            '',
            ...EnterpriseWikiMaintainerDecisionAiClient::repairResolutionRules(),
            '',
            'Return JSON only. No text outside JSON.',
        ]);
    }

    /**
     * The user prompt for one repair group: the source document, the current Wiki, every page this
     * decision plans (so a candidate can name an owning page that is being created in this same
     * run), and then ONLY the group's own objects and issues.
     *
     * @param  array{title: string, filename: string}  $sourceMeta
     * @param  string  $sourceContentBlock  Pre-rendered SOURCE ELEMENTS/SOURCE TEXT block.
     * @param  array<int, array<string, mixed>>  $indexContext
     * @param  array<string, mixed>  $decision
     * @param  array{object_ids: list<string>, issues: list<string>, context_object_ids?: list<string>}  $group
     * @param  list<array<string, mixed>>  $figureCandidates
     */
    public static function userPrompt(
        array $sourceMeta,
        string $sourceContentBlock,
        array $indexContext,
        array $decision,
        array $group,
        array $figureCandidates = [],
    ): string {
        $title = (string) ($sourceMeta['title'] ?? '');

        return implode("\n", [
            'SOURCE METADATA:',
            "Title: {$title}",
            '',
            $sourceContentBlock,
            '',
            'EXISTING WIKI INDEX ('.count($indexContext).' pages):',
            $indexContext !== []
                ? (string) json_encode($indexContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : 'No pages yet.',
            '',
            self::plannedPagesBlock($decision),
            '',
            EnterpriseWikiMaintainerDecisionAiClient::figureCandidatesBlock($figureCandidates),
            '',
            self::objectsToRepairBlock($decision, $group['object_ids']),
            '',
            self::clusterContextBlock($decision, $group),
            '',
            self::referencedByBlock($decision, $group['object_ids']),
            '',
            'ISSUES TO FIX:',
            implode("\n", array_map(static fn (string $issue): string => "- {$issue}", $group['issues'])),
        ]);
    }

    /**
     * Every page the decision plans, as title + type + action. This is what makes a same-run page a
     * nameable owner during repair: without it, a repair call can only see the objects it was given
     * and would keep pointing at pages it cannot prove exist.
     *
     * @param  array<string, mixed>  $decision
     */
    private static function plannedPagesBlock(array $decision): string
    {
        $lines = [];

        foreach (EnterpriseWikiMaintainerDecisionObjectIndex::SINGLETON_SLOTS as $slot) {
            $entry = $decision[$slot] ?? null;

            if (is_array($entry) && ($entry['title'] ?? '') !== '') {
                $lines[] = sprintf('- %s: "%s" (the document itself — never an owning page)', $slot, $entry['title']);
            }
        }

        foreach ([
            EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_CONCEPT_PAGES => 'concept page',
            EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_ENTITY_PAGES => 'entity page',
        ] as $collection => $label) {
            foreach ((array) ($decision[$collection] ?? []) as $index => $entry) {
                if (! is_array($entry) || ($entry['title'] ?? '') === '') {
                    continue;
                }

                $lines[] = sprintf(
                    '- %s: "%s" (%s, action "%s"%s)',
                    EnterpriseWikiMaintainerDecisionObjectIndex::objectId($collection, (int) $index),
                    $entry['title'],
                    $label,
                    (string) ($entry['action'] ?? 'create'),
                    isset($entry['page_id']) && is_int($entry['page_id']) ? ', page_id '.$entry['page_id'] : '',
                );
            }
        }

        return implode("\n", array_merge(
            ['PAGES PLANNED IN THIS DECISION (valid owning pages, alongside the wiki index above):'],
            $lines !== [] ? $lines : ['(none)'],
        ));
    }

    /**
     * The rest of a cluster this call only holds part of.
     *
     * A finding like "these seven candidates all came from one bullet list — consolidate them" can
     * name more objects than one bounded call may answer for. Splitting the cluster across calls is
     * still right (each call stays bounded), but only if every call decides against the SAME
     * picture: without this block, two calls would each pick their own owning page for one cluster.
     *
     * @param  array<string, mixed>  $decision
     * @param  array{object_ids: list<string>, issues: list<string>, context_object_ids?: list<string>}  $group
     */
    private static function clusterContextBlock(array $decision, array $group): string
    {
        $contextIds = array_values(array_diff($group['context_object_ids'] ?? [], $group['object_ids']));

        if ($contextIds === []) {
            return '';
        }

        $lines = [
            'REST OF THIS CLUSTER ('.count($contextIds).' objects, being corrected in parallel calls):',
            'The issues above concern this whole cluster, but you may only edit the objects under',
            'OBJECTS TO REPAIR. The rest are listed here so you decide against the same picture: when',
            'the fix is to consolidate the cluster onto one owning page, name the SAME overarching',
            'page the whole cluster should sit under, and only create it if that page is one of your',
            'own objects.',
        ];

        foreach ($contextIds as $objectId) {
            $object = EnterpriseWikiMaintainerDecisionObjectIndex::object($decision, $objectId) ?? [];
            $lines[] = sprintf(
                '- [%s] %s',
                $objectId,
                EnterpriseWikiMaintainerDecisionObjectIndex::label($objectId, $object),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Which OTHER pages in the decision point at the objects being repaired.
     *
     * The first bounded runtime verification showed why this belongs in the prompt: a repair
     * demoted a concept the source article's other pages linked onward to, and two pages outside
     * the group — pages the validators had already accepted — were left pointing at a page that no
     * longer existed. The merge correctly refused to touch them and full revalidation caught it,
     * but the model had no way to see the cost of removing that page in the first place.
     *
     * This is context only: these pages are NOT repairable in this call, and saying so plainly is
     * what makes the constraint actionable rather than a trap.
     *
     * @param  array<string, mixed>  $decision
     * @param  list<string>  $objectIds
     */
    private static function referencedByBlock(array $decision, array $objectIds): string
    {
        $groupTitles = [];

        foreach ($objectIds as $objectId) {
            $object = EnterpriseWikiMaintainerDecisionObjectIndex::object($decision, $objectId) ?? [];
            $title = trim((string) ($object['title'] ?? $object['name'] ?? ''));

            if ($title !== '') {
                $groupTitles[$title] = $objectId;
            }
        }

        if ($groupTitles === []) {
            return '';
        }

        $lines = [];
        $groupIds = array_flip($objectIds);

        foreach ([
            EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_CONCEPT_PAGES,
            EnterpriseWikiMaintainerDecisionObjectIndex::COLLECTION_ENTITY_PAGES,
        ] as $collection) {
            foreach ((array) ($decision[$collection] ?? []) as $index => $page) {
                $objectId = EnterpriseWikiMaintainerDecisionObjectIndex::objectId($collection, (int) $index);

                if (! is_array($page) || isset($groupIds[$objectId])) {
                    continue;
                }

                $lines = array_merge($lines, self::guidanceReferences($page, $objectId, $groupTitles));
            }
        }

        foreach (EnterpriseWikiMaintainerDecisionObjectIndex::SINGLETON_SLOTS as $slot) {
            $entry = $decision[$slot] ?? null;

            if (is_array($entry) && ! isset($groupIds[$slot])) {
                $lines = array_merge($lines, self::guidanceReferences($entry, $slot, $groupTitles));
            }
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", array_merge([
            'REFERENCED BY (pages OUTSIDE this repair that point at the objects above):',
            'You cannot edit these pages here. If you remove or rename a page they point to, they are',
            'left pointing at nothing and the whole decision is rejected. Prefer a fix that keeps the',
            'referenced page — or, when it genuinely must go, make sure another object in THIS repair',
            'takes over the topic under the same title.',
        ], $lines));
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, string>  $groupTitles
     * @return list<string>
     */
    private static function guidanceReferences(array $page, string $objectId, array $groupTitles): array
    {
        $lines = [];

        foreach ((array) ($page['related_page_guidance'] ?? []) as $guidance) {
            $target = is_array($guidance) ? trim((string) ($guidance['page_title'] ?? '')) : '';

            if ($target === '') {
                continue;
            }

            foreach ($groupTitles as $groupTitle => $groupObjectId) {
                if (EnterpriseWikiConceptIdentityMatcher::sameIdentity($target, (string) $groupTitle)) {
                    $lines[] = sprintf(
                        '- %s ("%s") points to "%s" [%s]',
                        $objectId,
                        (string) ($page['title'] ?? '?'),
                        $target,
                        $groupObjectId,
                    );
                }
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  list<string>  $objectIds
     */
    private static function objectsToRepairBlock(array $decision, array $objectIds): string
    {
        $parts = [
            'OBJECTS TO REPAIR ('.count($objectIds).'):',
            'These are the only objects you may replace or remove. Their object_id is shown in brackets.',
        ];

        foreach ($objectIds as $objectId) {
            $object = EnterpriseWikiMaintainerDecisionObjectIndex::object($decision, $objectId) ?? [];

            $parts[] = '';
            $parts[] = sprintf(
                '[%s] %s',
                $objectId,
                EnterpriseWikiMaintainerDecisionObjectIndex::label($objectId, $object),
            );
            $parts[] = (string) json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $parts);
    }

    /**
     * Structural validation of a raw delta. Object CONTENT is deliberately not validated here —
     * the merged decision is re-parsed by EnterpriseWikiMaintainerDecisionPrompt::parse() and
     * re-checked by every validator afterwards, so a delta can never introduce an object that
     * would not have been accepted in a first-pass decision.
     *
     * @param  array<string, mixed>  $raw
     * @return string[] Empty when structurally valid.
     */
    public static function validate(array $raw): array
    {
        $errors = [];
        $lists = array_merge(array_keys(self::REPAIR_LISTS), [self::SOURCE_PAGE_LIST]);

        foreach ($lists as $list) {
            if (! array_key_exists($list, $raw)) {
                continue;
            }

            if (! is_array($raw[$list])) {
                $errors[] = "{$list} must be an array.";

                continue;
            }

            foreach ($raw[$list] as $i => $entry) {
                $errors = array_merge($errors, self::validateEntry($entry, "{$list}[{$i}]", $list));
            }
        }

        if (array_key_exists('notes', $raw) && $raw['notes'] !== null && ! is_string($raw['notes'])) {
            $errors[] = 'notes must be a string or null.';
        }

        return $errors;
    }

    /** @return string[] */
    private static function validateEntry(mixed $entry, string $ctx, string $list): array
    {
        if (! is_array($entry)) {
            return ["{$ctx} must be an object."];
        }

        $operation = $entry['operation'] ?? null;

        if (! is_string($operation) || ! in_array($operation, self::OPERATIONS, true)) {
            return ["{$ctx}.operation must be one of: ".implode(', ', self::OPERATIONS).'.'];
        }

        $errors = [];
        $objectId = $entry['object_id'] ?? null;
        $object = $entry['object'] ?? null;

        if ($operation === self::OPERATION_ADD) {
            if ($list === self::SOURCE_PAGE_LIST) {
                $errors[] = "{$ctx}.operation \"add\" is not valid for a source page — a source document has exactly one article and one summary.";
            }

            if (! is_array($object) || $object === []) {
                $errors[] = "{$ctx}.object is required for operation \"add\".";
            }

            return $errors;
        }

        if (! is_string($objectId) || trim($objectId) === '') {
            $errors[] = "{$ctx}.object_id is required for operation \"{$operation}\".";
        }

        if ($operation === self::OPERATION_REPLACE && (! is_array($object) || $object === [])) {
            $errors[] = "{$ctx}.object is required for operation \"replace\".";
        }

        if ($operation === self::OPERATION_REMOVE) {
            if ($list === self::SOURCE_PAGE_LIST) {
                $errors[] = "{$ctx}.operation \"remove\" is not valid for a source page — a source document always has an article and a summary.";
            }

            if (is_array($object) && $object !== []) {
                $errors[] = "{$ctx}.object must be null for operation \"remove\".";
            }
        }

        return $errors;
    }

    /**
     * Validate and normalise a raw delta into a flat, ordered operation list.
     *
     * @param  array<string, mixed>  $raw
     * @return array{operations: list<array{collection: string, object_id: ?string, operation: string, object: ?array<string, mixed>}>, notes: ?string}
     *
     * @throws InvalidArgumentException when the delta is structurally invalid.
     */
    public static function parse(array $raw): array
    {
        $errors = self::validate($raw);

        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid maintainer decision repair delta: '.implode(' | ', $errors));
        }

        $operations = [];

        foreach (self::REPAIR_LISTS as $list => $collection) {
            foreach ((array) ($raw[$list] ?? []) as $entry) {
                $operations[] = self::normalizeEntry((array) $entry, $collection);
            }
        }

        foreach ((array) ($raw[self::SOURCE_PAGE_LIST] ?? []) as $entry) {
            $entry = (array) $entry;
            $operations[] = self::normalizeEntry($entry, (string) ($entry['object_id'] ?? ''));
        }

        return [
            'operations' => $operations,
            'notes' => isset($raw['notes']) && is_string($raw['notes']) ? $raw['notes'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{collection: string, object_id: ?string, operation: string, object: ?array<string, mixed>}
     */
    private static function normalizeEntry(array $entry, string $collection): array
    {
        $objectId = $entry['object_id'] ?? null;
        $object = $entry['object'] ?? null;

        return [
            'collection' => $collection,
            'object_id' => is_string($objectId) && trim($objectId) !== '' ? trim($objectId) : null,
            'operation' => (string) $entry['operation'],
            'object' => is_array($object) && $object !== [] ? $object : null,
        ];
    }
}
