<?php

namespace App\Services\EnterpriseWiki;

/**
 * Plan §18, P0-1: no material part of a source document may disappear silently between the
 * document and the Wiki plan.
 *
 * Run 962 is the reason. The planner was given all 51 elements of a short method note and bound 15
 * of them to pages; article and concept generation only ever see the elements bound to them, so the
 * roles in the review, the cause/factor/barrier analysis, retrospective hunting, the feedback loop
 * and the consent rules for sharing were never written — and nothing anywhere noticed. Every
 * existing check ran from plan to output ("is each planned topic grounded and written?"), none from
 * source to plan.
 *
 * This validator is that missing direction, and it is deliberately structural:
 *
 *  - Coverage is measured on what generation will actually receive: each page's owned topics are
 *    bound through EnterpriseWikiPlannedSectionEvidenceResolver::bindPlannedElements(), so a key
 *    the per-section limits drop counts as uncovered instead of covered.
 *  - The unit is the document's own structure: a paragraph together with the list it introduces,
 *    grouped into the sections EnterpriseWikiDocumentSectionMap already addresses as `sec-N`.
 *  - It never asks for one page per section. The source_article is the default owner of anything
 *    no concept or entity page owns, and the repair it triggers adds topics there.
 *  - A section may be left out on purpose, but only explicitly: source_article.excluded_sections
 *    names the section, one of four reasons and a justification. A summary does not count as
 *    coverage — it is an orientation page, not where knowledge is kept.
 *
 * Issues carry the `{object:source_article}` marker so the existing bounded delta repair can
 * attribute them structurally and edit exactly the article. Coverage issues are SOFT in
 * EnterpriseWikiMaintainerDecisionService: they get the same bounded repair rounds as every other
 * issue, but what is left afterwards does not fail the run — it is reported by QA instead
 * (EnterpriseWikiPostIngestQaService, `checks.source_coverage`).
 */
class EnterpriseWikiSourceCoverageValidator
{
    public const ISSUE_PREFIX = '[source_coverage]';

    public const ATTRIBUTION_MARKER = '{object:source_article}';

    public const EXCLUSION_REASONS = ['example', 'front_matter', 'duplicate_of_existing', 'out_of_scope'];

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const SECTION_COVERED = 'covered';

    public const SECTION_PARTIAL = 'partial';

    public const SECTION_UNCOVERED = 'uncovered';

    public const SECTION_EXCLUDED = 'excluded';

    public const SECTION_IMMATERIAL = 'immaterial';

    /** A unit (paragraph + its list, a list, a table) shorter than this carries no knowledge of its own. */
    private const MIN_MATERIAL_UNIT_CHARS = 60;

    /** A justification shorter than this is a label, not a reason anyone could check. */
    private const MIN_JUSTIFICATION_CHARS = 15;

    /** Exclusions may never remove more than this share of the document's material text. */
    private const MAX_EXCLUDED_SHARE = 0.5;

    private const CATALOG_TYPES = ['paragraph', 'list_item', 'listitem', 'table_row'];

    public function __construct(
        private readonly EnterpriseWikiPlannedSectionEvidenceResolver $evidenceResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $sourceElements  The planning context's elements, in document order.
     * @param  list<int>  $knownPageIds  Existing pages the planner was shown (wiki index + candidates).
     * @return list<string>
     */
    public function findIssues(array $decision, array $sourceElements, array $knownPageIds = []): array
    {
        $report = $this->evaluate($decision, $sourceElements, $knownPageIds);

        if ($report['status'] === self::STATUS_NOT_APPLICABLE) {
            return [];
        }

        $issues = [];
        $prefix = self::ISSUE_PREFIX.' '.self::ATTRIBUTION_MARKER.' ';

        foreach ($report['invalid_exclusions'] as $invalid) {
            $issues[] = $prefix."source_article.excluded_sections names [{$invalid['section_key']}]: {$invalid['problem']} "
                .'Correct the exclusion or remove it and cover the section with an owned topic.';
        }

        foreach ($report['sections'] as $section) {
            if ($section['status'] === self::SECTION_UNCOVERED) {
                $issues[] = $prefix."Source section [{$section['section_key']}] \"{$section['label']}\" is not covered by any planned page "
                    .'and is not excluded. Its material elements ['.implode(', ', $section['uncovered_element_keys']).'] would never reach the Wiki. '
                    .'Give source_article an owned topic for it (or extend an existing topic, within the key limit), or — only if it is an example, '
                    .'front matter, a duplicate of an existing page or out of scope — add it to source_article.excluded_sections with a concrete justification.';
            } elseif ($section['status'] === self::SECTION_PARTIAL) {
                $issues[] = $prefix."Source section [{$section['section_key']}] \"{$section['label']}\" is only partly covered: the material elements ["
                    .implode(', ', $section['uncovered_element_keys']).'] are on no planned page. Bind them to an owned topic of source_article '
                    .'(a new topic or an existing one with room under the key limit). A paragraph ending with ":" brings its list with it.';
            }
        }

        if ($report['excessive_exclusion']) {
            $issues[] = $prefix.'source_article.excluded_sections leaves out more than half of the document\'s material text. '
                .'Exclusions are for examples, front matter, duplicates and out-of-scope material only — cover the knowledge instead.';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $sourceElements
     * @param  list<int>  $knownPageIds
     * @return array{
     *     status: string,
     *     sections: list<array{section_key: string, label: string, status: string, material_element_keys: list<string>, uncovered_element_keys: list<string>, exclusion: ?array<string, mixed>}>,
     *     uncovered_section_keys: list<string>,
     *     partial_section_keys: list<string>,
     *     excluded_section_keys: list<string>,
     *     material_element_count: int,
     *     covered_element_count: int,
     *     invalid_exclusions: list<array{section_key: string, problem: string}>,
     *     excessive_exclusion: bool,
     * }
     */
    public function evaluate(array $decision, array $sourceElements, array $knownPageIds = []): array
    {
        $ordered = array_values(array_filter(
            $sourceElements,
            static fn (mixed $element): bool => is_array($element) && trim((string) ($element['source_element_key'] ?? '')) !== '',
        ));
        $catalog = array_values(array_filter(
            $ordered,
            static fn (array $element): bool => in_array($element['source_element_type'] ?? null, self::CATALOG_TYPES, true),
        ));

        // A non-DOCX import has one whole-document element and no structure to measure against; a
        // no-action decision has no article to own anything.
        if (count($catalog) < 2 || ! is_array($decision['source_article'] ?? null) || $decision['source_article'] === []) {
            return $this->emptyReport(self::STATUS_NOT_APPLICABLE);
        }

        $map = EnterpriseWikiDocumentSectionMap::build($catalog);
        $covered = $this->coveredKeys($decision, $ordered);
        $exclusions = $this->exclusions($decision);
        $validSectionKeys = array_flip(array_merge(
            EnterpriseWikiDocumentSectionMap::sectionKeys($map),
            $map['sectionless_element_keys'] !== [] ? [EnterpriseWikiDocumentSectionMap::SECTIONLESS_KEY] : [],
        ));
        $knownPages = array_flip(array_map('intval', $knownPageIds));
        $units = $this->materialUnits($catalog);

        $sections = [];
        $invalid = [];
        $materialChars = 0;
        $excludedChars = 0;
        $materialCount = 0;
        $coveredCount = 0;

        foreach ($exclusions as $sectionKey => $exclusion) {
            if (! isset($validSectionKeys[$sectionKey])) {
                $invalid[] = ['section_key' => $sectionKey, 'problem' => 'no such section exists in this document.'];
            }
        }

        foreach ($this->sectionsWithSectionless($map) as $section) {
            $sectionKey = $section['key'];
            $sectionUnits = array_values(array_filter(
                $units,
                static fn (array $unit): bool => ($map['section_by_element'][$unit['keys'][0]] ?? null) === $sectionKey,
            ));
            $materialKeys = array_merge(...array_map(static fn (array $unit): array => $unit['keys'], $sectionUnits ?: [['keys' => []]]));
            $sectionChars = array_sum(array_column($sectionUnits, 'chars'));
            $uncoveredKeys = array_values(array_filter($materialKeys, static fn (string $key): bool => ! isset($covered[$key])));
            $coveredInSection = count($materialKeys) - count($uncoveredKeys);
            $exclusion = $exclusions[$sectionKey] ?? null;

            if ($exclusion !== null) {
                $problem = $this->exclusionProblem($exclusion, $coveredInSection > 0, $knownPages);

                if ($problem !== null) {
                    $invalid[] = ['section_key' => $sectionKey, 'problem' => $problem];
                    $exclusion = null;
                }
            }

            $materialChars += $sectionChars;
            $status = match (true) {
                $materialKeys === [] => self::SECTION_IMMATERIAL,
                $exclusion !== null => self::SECTION_EXCLUDED,
                $uncoveredKeys === [] => self::SECTION_COVERED,
                $coveredInSection === 0 => self::SECTION_UNCOVERED,
                default => self::SECTION_PARTIAL,
            };

            if ($status === self::SECTION_EXCLUDED) {
                $excludedChars += $sectionChars;
            } else {
                $materialCount += count($materialKeys);
                $coveredCount += $coveredInSection;
            }

            $sections[] = [
                'section_key' => $sectionKey,
                'label' => (string) $section['label'],
                'status' => $status,
                'material_element_keys' => $materialKeys,
                'uncovered_element_keys' => in_array($status, [self::SECTION_UNCOVERED, self::SECTION_PARTIAL], true) ? $uncoveredKeys : [],
                'exclusion' => $exclusion,
            ];
        }

        $byStatus = static fn (string $status): array => array_values(array_column(
            array_filter($sections, static fn (array $section): bool => $section['status'] === $status),
            'section_key',
        ));
        $uncoveredSections = $byStatus(self::SECTION_UNCOVERED);
        $partialSections = $byStatus(self::SECTION_PARTIAL);
        $excessive = $materialChars > 0 && $excludedChars / $materialChars > self::MAX_EXCLUDED_SHARE;

        return [
            'status' => match (true) {
                $uncoveredSections !== [] || $excessive => self::STATUS_INCOMPLETE,
                $partialSections !== [] || $invalid !== [] => self::STATUS_PARTIAL,
                default => self::STATUS_COMPLETE,
            },
            'sections' => $sections,
            'uncovered_section_keys' => $uncoveredSections,
            'partial_section_keys' => $partialSections,
            'excluded_section_keys' => $byStatus(self::SECTION_EXCLUDED),
            'material_element_count' => $materialCount,
            'covered_element_count' => $coveredCount,
            'invalid_exclusions' => $invalid,
            'excessive_exclusion' => $excessive,
        ];
    }

    /**
     * Keys that reach a page through generation: the bound owned topics of the article and every
     * concept/entity page, plus the keys patch targets apply to existing pages. The summary is
     * excluded on purpose (see class docblock).
     *
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $ordered
     * @return array<string, true>
     */
    private function coveredKeys(array $decision, array $ordered): array
    {
        $pages = [];

        if (is_array($decision['source_article'] ?? null)) {
            $pages[] = $decision['source_article'];
        }

        foreach (['concept_pages', 'entity_pages'] as $list) {
            foreach ((array) ($decision[$list] ?? []) as $page) {
                if (is_array($page)) {
                    $pages[] = $page;
                }
            }
        }

        $covered = [];

        foreach ($pages as $page) {
            foreach (EnterpriseWikiMaintainerDecisionPrompt::ownedTopicEntries($page['owned_topics'] ?? []) as $topic) {
                if ($topic['source_element_keys'] === []) {
                    continue;
                }

                foreach ($this->evidenceResolver->bindPlannedElements($topic['source_element_keys'], $ordered)['elements'] as $element) {
                    $covered[trim((string) $element['source_element_key'])] = true;
                }
            }
        }

        foreach ((array) ($decision['patch_targets'] ?? []) as $target) {
            foreach ((array) (is_array($target) ? ($target['source_element_keys'] ?? []) : []) as $key) {
                if (is_string($key) && trim($key) !== '') {
                    $covered[trim($key)] = true;
                }
            }
        }

        return $covered;
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, array<string, mixed>>
     */
    private function exclusions(array $decision): array
    {
        $exclusions = [];

        foreach ((array) (($decision['source_article'] ?? [])['excluded_sections'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sectionKey = trim((string) ($entry['section_key'] ?? ''));

            if ($sectionKey !== '') {
                $exclusions[$sectionKey] = $entry;
            }
        }

        return $exclusions;
    }

    /**
     * @param  array<string, mixed>  $exclusion
     * @param  array<int, int>  $knownPages
     */
    private function exclusionProblem(array $exclusion, bool $sectionIsCited, array $knownPages): ?string
    {
        $reason = (string) ($exclusion['reason'] ?? '');

        if (! in_array($reason, self::EXCLUSION_REASONS, true)) {
            return 'reason must be one of '.implode(', ', self::EXCLUSION_REASONS).'.';
        }

        if (mb_strlen(trim((string) ($exclusion['justification'] ?? ''))) < self::MIN_JUSTIFICATION_CHARS) {
            return 'the justification is missing or too short to be checked — say concretely why this section is left out.';
        }

        if ($sectionIsCited) {
            return 'the section is excluded but its elements are also cited by a planned page — a section is either covered or excluded, never both.';
        }

        if ($reason === 'duplicate_of_existing') {
            $pageId = (int) ($exclusion['existing_page_id'] ?? 0);

            if ($pageId <= 0 || ($knownPages !== [] && ! isset($knownPages[$pageId]))) {
                return 'duplicate_of_existing must name, in existing_page_id, the existing Wiki page from the index that already explains this section.';
            }
        }

        return null;
    }

    /**
     * Paragraph + the list it introduces is one unit; a list without a lead-in is one unit; every
     * other element is its own unit. Only units with real text are material.
     *
     * @param  list<array<string, mixed>>  $catalog
     * @return list<array{keys: list<string>, chars: int}>
     */
    private function materialUnits(array $catalog): array
    {
        $units = [];
        $current = null;

        foreach ($catalog as $position => $element) {
            $key = trim((string) $element['source_element_key']);
            $chars = mb_strlen(trim((string) ($element['reference_text'] ?? $element['display_text'] ?? '')));
            $continues = $current !== null
                && EnterpriseWikiPlannedSectionEvidenceResolver::isListItem($element)
                && $current['open_list']
                && EnterpriseWikiDocumentSectionMap::sectionLabel($catalog[$position - 1]) === EnterpriseWikiDocumentSectionMap::sectionLabel($element);

            if ($continues) {
                $current['keys'][] = $key;
                $current['chars'] += $chars;

                continue;
            }

            if ($current !== null) {
                $units[] = $current;
            }

            $current = [
                'keys' => [$key],
                'chars' => $chars,
                'open_list' => EnterpriseWikiPlannedSectionEvidenceResolver::isLeadIn($element)
                    || EnterpriseWikiPlannedSectionEvidenceResolver::isListItem($element),
            ];
        }

        if ($current !== null) {
            $units[] = $current;
        }

        return array_values(array_map(
            static fn (array $unit): array => ['keys' => $unit['keys'], 'chars' => $unit['chars']],
            array_filter($units, static fn (array $unit): bool => $unit['chars'] >= self::MIN_MATERIAL_UNIT_CHARS),
        ));
    }

    /**
     * @param  array{sections: list<array<string, mixed>>, sectionless_element_keys: list<string>}  $map
     * @return list<array{key: string, label: string}>
     */
    private function sectionsWithSectionless(array $map): array
    {
        $sections = array_map(
            static fn (array $section): array => ['key' => (string) $section['key'], 'label' => (string) $section['label']],
            $map['sections'],
        );

        if ($map['sectionless_element_keys'] !== []) {
            $sections[] = ['key' => EnterpriseWikiDocumentSectionMap::SECTIONLESS_KEY, 'label' => '(no section)'];
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    private function emptyReport(string $status): array
    {
        return [
            'status' => $status,
            'sections' => [],
            'uncovered_section_keys' => [],
            'partial_section_keys' => [],
            'excluded_section_keys' => [],
            'material_element_count' => 0,
            'covered_element_count' => 0,
            'invalid_exclusions' => [],
            'excessive_exclusion' => false,
        ];
    }
}
