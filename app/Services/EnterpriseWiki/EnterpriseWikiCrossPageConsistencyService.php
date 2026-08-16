<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Services\Ai\Wiki\WikiCrossPageConsistencyAiClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 8K-4, first slice — POST-PATCH CROSS-PAGE CURRENT-STATE CONSISTENCY.
 *
 * Responsibility boundary:
 *  - 8K-2 decides WHAT knowledge should change and WHERE.
 *  - 8K-3 performs the safe, strict, local patch.
 *  - 8K-4 (this service) checks whether the RESULT is consistent across the customer's current Wiki.
 *
 * Its verification path is DETECTION ONLY. It never writes a page version or rewrites wording; its
 * entire output is EnterpriseWikiLintFinding rows, which the existing QA aggregation already consumes.
 * A separate reconciliation stage may ask this service for a read-only, high-confidence discovery
 * result before QA. That stage must still route every result through the ordinary bounded patch
 * resolver and deterministic patch engine — this class never performs a repair itself.
 *
 * THE CURRENT-STATE PRINCIPLE this implements:
 *   Current canonical Wiki content should describe current truth, not change history.
 * History belongs in page versions, in the source/change document, and in run history — not carried
 * forward inside canonical pages as "was X, now Y".
 *
 * HARD RULE — this is not "grep the old value and call it stale". Containing the old substance is
 * only a PREFILTER. Whether an occurrence is a stale current assertion, a legitimate historical
 * record, a change-document record, or a conceptual mention is a separate classification step, and a
 * blocking finding requires high confidence. Structural facts stay deterministic; only the temporal
 * reading of the wording is delegated to a bounded classification call.
 *
 * Deliberately NOT bounded by EnterpriseWikiPatchCandidateService::MAX_CANDIDATES. Candidate
 * discovery and this post-check have different jobs: discovery decides what the maintainer is asked
 * about (and must stay small enough to prompt), while this pass is the safety net that catches the
 * owners discovery could not offer. Sharing the cap would make the net exactly as blind as the thing
 * it exists to backstop.
 */
class EnterpriseWikiCrossPageConsistencyService
{
    /** Deterministic prefilter breadth. Far above MAX_CANDIDATES; a bound only against runaway cost. */
    public const MAX_PAGES_CONSIDERED = 500;

    /** Classification calls per run. Exceeding it is logged, never silently dropped. */
    public const MAX_CLASSIFICATIONS = 40;

    private const EXCERPT_WINDOW_CHARS = 1200;

    private const PAGE_STATUSES_EXCLUDED = [
        EnterpriseWikiPage::STATUS_ARCHIVED,
        EnterpriseWikiPage::STATUS_SUPERSEDED,
        EnterpriseWikiPage::STATUS_REJECTED,
    ];

    public function __construct(
        private readonly WikiCrossPageConsistencyAiClient $aiClient,
    ) {}

    /**
     * @return array{
     *   assertions: int, pages_considered: int, occurrences: int, classified: int,
     *   findings_created: int, findings_resolved: int, errors: int, warnings: int,
     *   classifications_skipped: int
     * }
     */
    public function checkForRun(EnterpriseWikiIngestRun $run): array
    {
        $counts = [
            'assertions' => 0,
            'pages_considered' => 0,
            'occurrences' => 0,
            'classified' => 0,
            'findings_created' => 0,
            'findings_resolved' => 0,
            'errors' => 0,
            'warnings' => 0,
            'classifications_skipped' => 0,
        ];

        $assertions = $this->changeAssertionsForRun($run);
        $counts['assertions'] = count($assertions);

        if ($assertions === []) {
            // Nothing substantive changed (e.g. an all-preserve run), so there is no superseded
            // substance to look for. Resolve any findings this run previously opened.
            $counts['findings_resolved'] = $this->resolveUntouched($run, []);

            return $counts;
        }

        $pages = $this->livePagesWithCurrentVersion($run);
        $counts['pages_considered'] = count($pages);
        $documentDerivedPageIds = $this->documentDerivedPageIds($run);
        $patchTargetPageIds = $this->patchTargetPageIds($assertions);

        $occurrences = [];

        foreach ($assertions as $assertion) {
            foreach ($pages as $row) {
                $occurrence = $this->occurrenceFor(
                    $assertion,
                    $row,
                    in_array($row['page']->id, $documentDerivedPageIds, true),
                    in_array($row['page']->id, $patchTargetPageIds, true),
                );

                if ($occurrence !== null) {
                    $occurrences[] = $occurrence;
                }
            }
        }

        $counts['occurrences'] = count($occurrences);
        $languageCode = (string) ($run->customer?->language?->code ?? 'no');
        $touchedIds = [];

        foreach ($occurrences as $index => $occurrence) {
            if ($index >= self::MAX_CLASSIFICATIONS) {
                $counts['classifications_skipped']++;

                continue;
            }

            $verdict = $this->classify($occurrence, $languageCode);

            if ($verdict['source'] === 'ai') {
                $counts['classified']++;
            }

            $finding = $this->findingFor($occurrence, $verdict);

            $this->logOccurrence($run, $occurrence, $verdict, $finding);

            if ($finding === null) {
                continue;
            }

            $id = $this->upsertFinding($run, $occurrence, $finding, $verdict);
            $touchedIds[] = $id;
            $counts['findings_created']++;

            $finding['severity'] === EnterpriseWikiLintFinding::SEVERITY_ERROR
                ? $counts['errors']++
                : $counts['warnings']++;
        }

        if ($counts['classifications_skipped'] > 0) {
            Log::warning('[WIKI_CROSS_PAGE] Classification cap reached — some occurrences were not classified.', [
                'run_id' => $run->id,
                'cap' => self::MAX_CLASSIFICATIONS,
                'skipped' => $counts['classifications_skipped'],
            ]);
        }

        $counts['findings_resolved'] = $this->resolveUntouched($run, $touchedIds);

        Log::info('[WIKI_CROSS_PAGE] Cross-page consistency check completed.', [
            'run_id' => $run->id,
            'assertions' => $counts['assertions'],
            'pages_considered' => $counts['pages_considered'],
            'occurrences' => $counts['occurrences'],
            'ai_classifications' => $counts['classified'],
            'findings_created' => $counts['findings_created'],
            'findings_resolved' => $counts['findings_resolved'],
            'errors' => $counts['errors'],
            'warnings' => $counts['warnings'],
        ]);

        return $counts;
    }

    /**
     * Find non-primary pages that still assert a changed fact as current, and compile only the
     * narrowest patch shape that can be proved from the already-authorised change assertion.
     *
     * This is intentionally not a second candidate-ranking path. It starts only from a validated
     * `replace` assertion already present in the run's decision, examines live current versions in
     * the same bounded customer scope as the final consistency check, and requires the same
     * high-confidence temporal classification that makes a stale assertion blocking in QA.
     *
     * The returned targets are proposals, not permission to mutate. The reconciliation caller must
     * validate each one with EnterpriseWikiPatchTargetResolver before giving it to the deterministic
     * patch engine. An unrepresentable paraphrase is reported as unresolved and deliberately left
     * for the final strict check/QA instead of being guessed at.
     *
     * @return array{
     *   targets: list<array<string, mixed>>, unresolved: list<array<string, mixed>>,
     *   assertions: int, pages_considered: int, occurrences: int, classified: int,
     *   classifications_skipped: int
     * }
     */
    public function discoverAdditionalPatchTargetsForRun(EnterpriseWikiIngestRun $run): array
    {
        $result = [
            'targets' => [],
            'unresolved' => [],
            'assertions' => 0,
            'pages_considered' => 0,
            'occurrences' => 0,
            'classified' => 0,
            'classifications_skipped' => 0,
        ];

        $assertions = $this->changeAssertionsForRun($run);
        $result['assertions'] = count($assertions);

        if ($assertions === []) {
            return $result;
        }

        $pages = $this->livePagesWithCurrentVersion($run);
        $result['pages_considered'] = count($pages);
        $documentDerivedPageIds = $this->documentDerivedPageIds($run);
        $primaryTargetPageIds = $this->patchTargetPageIds($assertions);
        $languageCode = (string) ($run->customer?->language?->code ?? 'no');
        $classified = 0;
        $seen = [];

        foreach ($assertions as $assertion) {
            foreach ($pages as $row) {
                $pageId = (int) $row['page']->id;

                // Primary targets have their own authorised patch plan. Retrying or widening those
                // from a consistency observation would hide a primary patch failure.
                if (in_array($pageId, $primaryTargetPageIds, true)
                    || in_array($pageId, $documentDerivedPageIds, true)) {
                    continue;
                }

                $occurrence = $this->occurrenceFor($assertion, $row, false, false);

                if ($occurrence === null) {
                    continue;
                }

                $result['occurrences']++;

                if ($classified >= self::MAX_CLASSIFICATIONS) {
                    $result['classifications_skipped']++;

                    continue;
                }

                $verdict = $this->classify($occurrence, $languageCode);
                $classified++;
                $result['classified']++;

                if ($verdict['classification'] !== WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION
                    || $verdict['confidence'] !== WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH
                    || $occurrence['new_state_present'] === true) {
                    continue;
                }

                $compiled = $this->compileDerivedPatchTarget($assertion, $occurrence, $row);

                if ($compiled['target'] === null) {
                    $result['unresolved'][] = [
                        'page_id' => $pageId,
                        'page_version_id' => (int) $row['version']->id,
                        'topic' => $assertion['topic'],
                        'prefilter_signal' => $occurrence['prefilter_signal'],
                        'reason' => $compiled['reason'],
                    ];

                    continue;
                }

                $target = $compiled['target'];
                $key = implode('|', [
                    $target['target_page_id'],
                    $target['target_heading'] ?? '',
                    $target['superseded_substance'],
                    $target['replacement_substance'],
                ]);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $result['targets'][] = $target;
            }
        }

        if ($result['classifications_skipped'] > 0) {
            Log::warning('[WIKI_CROSS_PAGE] Reconciliation discovery classification cap reached.', [
                'run_id' => $run->id,
                'cap' => self::MAX_CLASSIFICATIONS,
                'skipped' => $result['classifications_skipped'],
            ]);
        }

        return $result;
    }

    /**
     * Convert one classified occurrence into an ordinary `replace` target without inventing prose.
     *
     * An exact old assertion can use the decision's authorised sentence. For a paraphrase, the only
     * safe automatic form is a one-to-one distinctive numeric token replacement (for example 30 →
     * 15). It remains confined to one immutable heading and is rejected later unless the exact token
     * occurs exactly once in that target area.
     *
     * @param  array<string, mixed>  $assertion
     * @param  array<string, mixed>  $occurrence
     * @param  array{page: EnterpriseWikiPage, version: EnterpriseWikiPageVersion}  $row
     * @return array{target: array<string, mixed>|null, reason: string}
     */
    private function compileDerivedPatchTarget(array $assertion, array $occurrence, array $row): array
    {
        $superseded = (string) $assertion['old'];
        $replacement = (string) $assertion['new'];

        if ($occurrence['old_substance_present'] !== true) {
            $oldAnchors = $this->valueAnchors($superseded, $replacement);
            $newAnchors = $this->valueAnchors($replacement, $superseded);

            if (count($oldAnchors) !== 1 || count($newAnchors) !== 1) {
                return [
                    'target' => null,
                    'reason' => 'Paraphrased current assertion has no unambiguous one-to-one numeric replacement.',
                ];
            }

            $superseded = $oldAnchors[0];
            $replacement = $newAnchors[0];
        }

        if ($superseded === '' || $replacement === '') {
            return ['target' => null, 'reason' => 'No exact bounded replacement could be compiled.'];
        }

        return [
            'target' => [
                'target_page_id' => (int) $row['page']->id,
                'target_page_title' => (string) $row['page']->title,
                'target_page_type' => (string) $row['page']->page_type,
                'target_topic' => trim((string) $assertion['topic']) !== ''
                    ? (string) $assertion['topic']
                    : 'Current-state consistency repair',
                'target_heading' => $occurrence['heading'] !== null && $occurrence['heading'] !== ''
                    ? (string) $occurrence['heading']
                    : null,
                'relationship' => 'substance_changed',
                'operation' => 'replace',
                'superseded_substance' => $superseded,
                'replacement_substance' => $replacement,
                'source_element_keys' => $assertion['source_element_keys'],
                'preserve_topics' => [],
                'reason' => 'Derived from a high-confidence current assertion in a bounded cross-page consistency pass.',
            ],
            'reason' => '',
        ];
    }

    // =========================================================================
    // Seeds — the run's own patch targets, never a free-floating value search
    // =========================================================================

    /**
     * Every substance actually superseded by this run. Seeded exclusively from the decision's own
     * `replace` targets, so the check can only ever look for substance the run itself declared
     * changed — it never invents a value to hunt for.
     *
     * @return list<array{topic: string, old: string, new: string, target_page_ids: list<int>, source_element_keys: list<string>}>
     */
    public function changeAssertionsForRun(EnterpriseWikiIngestRun $run): array
    {
        $decision = (array) ($run->maintainer_decision_json ?? []);
        $assertions = [];

        foreach ((array) ($decision['patch_targets'] ?? []) as $target) {
            if (! is_array($target)) {
                continue;
            }

            if (($target['operation'] ?? null) !== 'replace' || ($target['relationship'] ?? null) !== 'substance_changed') {
                continue;
            }

            $old = trim((string) ($target['superseded_substance'] ?? ''));
            $new = trim((string) ($target['replacement_substance'] ?? ''));

            if ($old === '' || $new === '') {
                continue;
            }

            $key = $old.'|'.$new;

            if (isset($assertions[$key])) {
                $assertions[$key]['target_page_ids'][] = (int) ($target['target_page_id'] ?? 0);

                continue;
            }

            $assertions[$key] = [
                'topic' => trim((string) ($target['target_topic'] ?? '')),
                'old' => $old,
                'new' => $new,
                'target_page_ids' => [(int) ($target['target_page_id'] ?? 0)],
                'source_element_keys' => array_values(array_filter(
                    (array) ($target['source_element_keys'] ?? []),
                    static fn (mixed $k): bool => is_string($k) && trim($k) !== '',
                )),
            ];
        }

        return array_values($assertions);
    }

    /** @param list<array<string, mixed>> $assertions @return list<int> */
    private function patchTargetPageIds(array $assertions): array
    {
        $ids = [];

        foreach ($assertions as $assertion) {
            foreach ($assertion['target_page_ids'] as $id) {
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    // =========================================================================
    // Page scope — every live page, NOT the candidate cap
    // =========================================================================

    /** @return list<array{page: EnterpriseWikiPage, version: EnterpriseWikiPageVersion}> */
    private function livePagesWithCurrentVersion(EnterpriseWikiIngestRun $run): array
    {
        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $run->customer_id)
            ->whereNotIn('status', self::PAGE_STATUSES_EXCLUDED)
            ->orderBy('id')
            ->limit(self::MAX_PAGES_CONSIDERED)
            ->get();

        if ($pages->isEmpty()) {
            return [];
        }

        // Current versions only: a superseded version is history by definition and can never be a
        // stale CURRENT assertion.
        $versions = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pages->pluck('id'))
            ->where('is_current', true)
            ->get()
            ->keyBy('enterprise_wiki_page_id');

        $rows = [];

        foreach ($pages as $page) {
            $version = $versions->get($page->id);

            if ($version instanceof EnterpriseWikiPageVersion && trim((string) $version->content_markdown) !== '') {
                $rows[] = ['page' => $page, 'version' => $version];
            }
        }

        return $rows;
    }

    /**
     * Pages that ARE document-derived change records — a run's own source_article/source_summary.
     * On those, "old became new" is the page's whole purpose and must never be a finding.
     *
     * Identified structurally, via the proposed_slug each run's decision used to resolve the page
     * (the same identity EnterpriseWikiMaintainerDecisionApplyService resolves by), never by looking
     * for words like "change" or "decision" in a title.
     *
     * @return list<int>
     */
    private function documentDerivedPageIds(EnterpriseWikiIngestRun $run): array
    {
        $slugs = [];

        $runs = EnterpriseWikiIngestRun::query()
            ->where('customer_id', $run->customer_id)
            ->whereNotNull('maintainer_decision_json')
            ->get(['id', 'maintainer_decision_json']);

        foreach ($runs as $other) {
            $decision = (array) ($other->maintainer_decision_json ?? []);

            foreach (['source_article', 'source_summary'] as $slot) {
                $slug = trim((string) data_get($decision, $slot.'.proposed_slug', ''));

                if ($slug !== '') {
                    $slugs[$slug] = true;
                }
            }
        }

        if ($slugs === []) {
            return [];
        }

        return EnterpriseWikiPage::query()
            ->where('customer_id', $run->customer_id)
            ->whereIn('slug', array_keys($slugs))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    // =========================================================================
    // Occurrence detection — deterministic prefilter
    // =========================================================================

    /**
     * Does this page carry anything worth classifying for this assertion? Two deterministic signals:
     *
     *  1. `exact_old_substance` — the page states the superseded text verbatim.
     *  2. `value_anchor` — the page carries a distinctive value token that belongs to the OLD
     *     substance and NOT to the new one, which catches a page restating the same fact in its own
     *     words. Language-agnostic: an anchor is a token containing a digit, not a vocabulary entry.
     *
     * A page with no anchor at all cannot be asserting the old substance, so it produces no
     * occurrence and costs no classification call.
     *
     * @param  array<string, mixed>  $assertion
     * @param  array{page: EnterpriseWikiPage, version: EnterpriseWikiPageVersion}  $row
     * @return array<string, mixed>|null
     */
    private function occurrenceFor(array $assertion, array $row, bool $isDocumentDerived, bool $isPatchTarget): ?array
    {
        $markdown = (string) $row['version']->content_markdown;
        $old = (string) $assertion['old'];
        $new = (string) $assertion['new'];

        $position = mb_strpos($markdown, $old);
        $signal = null;

        if ($position !== false) {
            $signal = 'exact_old_substance';
        } else {
            foreach ($this->valueAnchors($old, $new) as $anchor) {
                $anchorPosition = mb_strpos($markdown, $anchor);

                if ($anchorPosition !== false) {
                    $position = $anchorPosition;
                    $signal = 'value_anchor';

                    break;
                }
            }
        }

        if ($signal === null) {
            return null;
        }

        return [
            'page_id' => (int) $row['page']->id,
            'page_title' => (string) $row['page']->title,
            'page_type' => (string) $row['page']->page_type,
            'page_version_id' => (int) $row['version']->id,
            'topic' => (string) $assertion['topic'],
            'old_substance' => $old,
            'new_substance' => $new,
            'old_substance_present' => mb_strpos($markdown, $old) !== false,
            'new_substance_present' => mb_strpos($markdown, $new) !== false,
            'new_state_present' => $this->statePresent($markdown, $new, $old),
            'is_document_derived' => $isDocumentDerived,
            'is_patch_target' => $isPatchTarget,
            'prefilter_signal' => $signal,
            'heading' => $this->headingAbove($markdown, (int) $position),
            'excerpt' => $this->excerptAround($markdown, (int) $position),
        ];
    }

    /**
     * Is the given state expressed on the page at all — verbatim, or via a value token distinctive to
     * it? Used symmetrically to the old-substance prefilter, and for a specific reason: a page that
     * says "was previously 120 units and is now 150 units" carries the NEW state without ever
     * repeating the canonical sentence verbatim. Testing only for the verbatim replacement would read
     * that page as "old value, no new value" and mistake a self-describing history sentence for a
     * page that simply never got updated.
     *
     * `new_substance_present` (strict verbatim) is kept separately for the classifier and the
     * finding metadata; this looser test only decides which of the two wording cases applies.
     */
    private function statePresent(string $markdown, string $state, string $otherState): bool
    {
        if (mb_strpos($markdown, $state) !== false) {
            return true;
        }

        foreach ($this->valueAnchors($state, $otherState) as $anchor) {
            if (mb_strpos($markdown, $anchor) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinctive value tokens carried by the old substance but not the new one. Purely structural:
     * any whitespace-delimited token containing a digit. No domain vocabulary, no units, no locale
     * assumptions — a token that also appears in the new substance is discarded, because it cannot
     * distinguish old from new.
     *
     * @return list<string>
     */
    private function valueAnchors(string $old, string $new): array
    {
        $anchors = [];

        foreach (preg_split('/\s+/u', $old, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $token = trim($token, ".,;:!?()[]{}\"'");

            if ($token === '' || ! preg_match('/\d/u', $token)) {
                continue;
            }

            if (mb_strpos($new, $token) !== false) {
                continue;
            }

            $anchors[$token] = true;
        }

        return array_keys($anchors);
    }

    /** The nearest markdown heading at or above $position — the section the occurrence sits in. */
    private function headingAbove(string $markdown, int $position): ?string
    {
        $before = mb_substr($markdown, 0, $position);
        $heading = null;

        foreach (preg_split('/\R/u', $before) ?: [] as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/u', trim($line), $matches) === 1) {
                $heading = trim($matches[1]);
            }
        }

        return $heading;
    }

    /**
     * A bounded window centred on the occurrence. The classifier has to see the wording it is
     * judging — the run-29 lesson: context cut short of the decisive text produces a wrong answer.
     */
    private function excerptAround(string $markdown, int $position): string
    {
        if (mb_strlen($markdown) <= self::EXCERPT_WINDOW_CHARS) {
            return $markdown;
        }

        $half = (int) floor(self::EXCERPT_WINDOW_CHARS / 2);
        $start = max(0, $position - $half);
        $excerpt = mb_substr($markdown, $start, self::EXCERPT_WINDOW_CHARS);

        return ($start > 0 ? '...' : '').$excerpt.($start + self::EXCERPT_WINDOW_CHARS < mb_strlen($markdown) ? '...' : '');
    }

    // =========================================================================
    // Classification — deterministic first, AI only for the temporal reading
    // =========================================================================

    /**
     * @param  array<string, mixed>  $occurrence
     * @return array{classification: string, confidence: string, reason: string, source: string, evidence_excerpt: string}
     */
    private function classify(array $occurrence, string $languageCode): array
    {
        // Structural exemption: a change record's job is to state old and new.
        if ($occurrence['is_document_derived'] === true) {
            return [
                'classification' => WikiCrossPageConsistencyAiClient::CLASSIFICATION_CHANGE_DOCUMENT_ASSERTION,
                'confidence' => WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH,
                'reason' => 'Page is a source/change-document page for one of this customer\'s runs.',
                'source' => 'deterministic',
                'evidence_excerpt' => '',
            ];
        }

        // NOTE: there is deliberately no "old text absent + new text present ⇒ resolved" shortcut
        // here. An occurrence only exists because the prefilter found the old state on the page in
        // SOME form, so a page whose canonical sentence was rewritten can still carry the old value
        // in its own words. Short-circuiting on the verbatim sentence alone would silently pass
        // exactly the paraphrased and "was X, now Y" cases this pass exists to catch. A page that
        // genuinely no longer expresses the old state produces no occurrence at all and never
        // reaches this method.
        if (! WikiCrossPageConsistencyAiClient::isAvailable()) {
            return [
                'classification' => WikiCrossPageConsistencyAiClient::CLASSIFICATION_UNKNOWN,
                'confidence' => WikiCrossPageConsistencyAiClient::CONFIDENCE_LOW,
                'reason' => 'Classification unavailable: wiki AI is disabled.',
                'source' => 'deterministic',
                'evidence_excerpt' => '',
            ];
        }

        try {
            $result = $this->aiClient->classify([
                'page_title' => $occurrence['page_title'],
                'page_type' => $occurrence['page_type'],
                'heading' => $occurrence['heading'],
                'excerpt' => $occurrence['excerpt'],
                'topic' => $occurrence['topic'],
                'old_substance' => $occurrence['old_substance'],
                'new_substance' => $occurrence['new_substance'],
                'old_substance_present' => $occurrence['old_substance_present'],
                'new_substance_present' => $occurrence['new_substance_present'],
            ], $languageCode);

            return [
                'classification' => $result['classification'],
                'confidence' => $result['confidence'],
                'reason' => $result['reason'],
                'source' => 'ai',
                'evidence_excerpt' => $result['evidence_excerpt'],
            ];
        } catch (Throwable $e) {
            // A classifier failure must never block a technically sound run, and must never be
            // silently read as "clean" either — it becomes an explicit non-blocking unknown.
            Log::warning('[WIKI_CROSS_PAGE] Classification failed — recorded as unknown.', [
                'page_id' => $occurrence['page_id'],
                'error' => $e->getMessage(),
            ]);

            return [
                'classification' => WikiCrossPageConsistencyAiClient::CLASSIFICATION_UNKNOWN,
                'confidence' => WikiCrossPageConsistencyAiClient::CONFIDENCE_LOW,
                'reason' => 'Classification call failed: '.$e->getMessage(),
                'source' => 'error',
                'evidence_excerpt' => '',
            ];
        }
    }

    /**
     * Classification + confidence → finding, or null for "this is fine".
     *
     * Blocking (error) requires BOTH a classification that means real contradiction AND high
     * confidence. Everything uncertain degrades to a non-blocking signal rather than stopping a run.
     *
     * @param  array<string, mixed>  $occurrence
     * @param  array<string, mixed>  $verdict
     * @return array{code: string, severity: string, message: string}|null
     */
    private function findingFor(array $occurrence, array $verdict): ?array
    {
        $classification = (string) $verdict['classification'];
        $isHighConfidence = $verdict['confidence'] === WikiCrossPageConsistencyAiClient::CONFIDENCE_HIGH;
        $topic = $occurrence['topic'] !== '' ? $occurrence['topic'] : 'the changed substance';

        // Legitimate: a change record, a page already carrying the new truth, or a page that only
        // discusses the topic without owning a value.
        if (in_array($classification, [
            WikiCrossPageConsistencyAiClient::CLASSIFICATION_CHANGE_DOCUMENT_ASSERTION,
            WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_REPLACEMENT,
            WikiCrossPageConsistencyAiClient::CLASSIFICATION_CONCEPTUAL_REFERENCE,
        ], true)) {
            return null;
        }

        if ($classification === WikiCrossPageConsistencyAiClient::CLASSIFICATION_HISTORICAL_ASSERTION) {
            // Not stale — the page is explicit that this was the former state. But a canonical
            // operational page normally should not carry change history at all: that belongs in the
            // version log and the change document. Reported as a separate, non-blocking quality
            // signal, deliberately never confused with stale knowledge.
            if ($occurrence['new_state_present'] === true) {
                return [
                    'code' => EnterpriseWikiLintFinding::CODE_HISTORICAL_WORDING_IN_CURRENT_CANONICAL_CONTENT,
                    'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
                    'message' => sprintf(
                        'Page describes both the superseded and the current state of "%s". Current canonical '
                        .'content should state current truth; the change history already lives in the page '
                        .'versions and the source document.',
                        $topic,
                    ),
                ];
            }

            return null;
        }

        if ($classification === WikiCrossPageConsistencyAiClient::CLASSIFICATION_CURRENT_ASSERTION) {
            if (! $isHighConfidence) {
                return $this->unknownFinding($topic, $verdict);
            }

            // Both states asserted as current on one page, or two pages disagreeing: a direct
            // contradiction rather than merely out-of-date text.
            if ($occurrence['new_state_present'] === true) {
                return [
                    'code' => EnterpriseWikiLintFinding::CODE_CROSS_PAGE_CURRENT_CONFLICT,
                    'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
                    'message' => sprintf(
                        'Page asserts the superseded state of "%s" as current while the current state is also '
                        .'present — the Wiki contradicts itself.',
                        $topic,
                    ),
                ];
            }

            return [
                'code' => EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION,
                'severity' => EnterpriseWikiLintFinding::SEVERITY_ERROR,
                'message' => sprintf(
                    'Page still states the superseded substance for "%s" as current fact after an '
                    .'authoritative change updated it elsewhere.',
                    $topic,
                ),
            ];
        }

        return $this->unknownFinding($topic, $verdict);
    }

    /** @return array{code: string, severity: string, message: string} */
    private function unknownFinding(string $topic, array $verdict): array
    {
        return [
            'code' => EnterpriseWikiLintFinding::CODE_CROSS_PAGE_CONSISTENCY_UNKNOWN,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => sprintf(
                'Page mentions substance affected by the change to "%s", but its current-versus-historical '
                .'status could not be determined with confidence (%s/%s). Reported for review rather than '
                .'blocking the run.',
                $topic,
                (string) $verdict['classification'],
                (string) $verdict['confidence'],
            ),
        ];
    }

    // =========================================================================
    // Findings — existing table, existing QA aggregation, idempotent per run
    // =========================================================================

    /**
     * @param  array<string, mixed>  $occurrence
     * @param  array{code: string, severity: string, message: string}  $finding
     * @param  array<string, mixed>  $verdict
     */
    private function upsertFinding(EnterpriseWikiIngestRun $run, array $occurrence, array $finding, array $verdict): int
    {
        $metadata = [
            'topic' => $occurrence['topic'],
            'old_substance' => $occurrence['old_substance'],
            'new_substance' => $occurrence['new_substance'],
            'old_substance_present' => $occurrence['old_substance_present'],
            'new_substance_present' => $occurrence['new_substance_present'],
            'new_state_present' => $occurrence['new_state_present'],
            'prefilter_signal' => $occurrence['prefilter_signal'],
            'heading' => $occurrence['heading'],
            'classification' => $verdict['classification'],
            'confidence' => $verdict['confidence'],
            'classified_by' => $verdict['source'],
            'evidence_excerpt' => $verdict['evidence_excerpt'],
            'reason' => $verdict['reason'],
            'is_patch_target' => $occurrence['is_patch_target'],
        ];

        // Keyed per (run, page, version, code) so a re-run reopens/updates rather than duplicating —
        // the same idempotency shape the applied-run lint findings use.
        $existing = EnterpriseWikiLintFinding::query()
            ->where('customer_id', $run->customer_id)
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $occurrence['page_id'])
            ->where('enterprise_wiki_page_version_id', $occurrence['page_version_id'])
            ->where('code', $finding['code'])
            ->first();

        if ($existing instanceof EnterpriseWikiLintFinding) {
            $existing->update([
                'severity' => $finding['severity'],
                'message' => $finding['message'],
                'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
                'resolved_at' => null,
                'detected_at' => now(),
                'metadata' => $metadata,
            ]);

            return (int) $existing->id;
        }

        $created = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $run->customer_id,
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $occurrence['page_id'],
            'enterprise_wiki_page_version_id' => $occurrence['page_version_id'],
            'enterprise_wiki_document_id' => $run->source_id,
            'code' => $finding['code'],
            'severity' => $finding['severity'],
            'message' => $finding['message'],
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
            'metadata' => $metadata,
        ]);

        return (int) $created->id;
    }

    /**
     * Close this run's previously-open cross-page findings that no longer apply. Scoped strictly to
     * this pass's own codes — the applied-run lint pass owns its codes and must not have them
     * resolved from here, exactly as it must not resolve these.
     *
     * @param  list<int>  $touchedIds
     */
    private function resolveUntouched(EnterpriseWikiIngestRun $run, array $touchedIds): int
    {
        $query = EnterpriseWikiLintFinding::query()
            ->where('customer_id', $run->customer_id)
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereIn('code', EnterpriseWikiLintFinding::CROSS_PAGE_CONSISTENCY_CODES)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN);

        if ($touchedIds !== []) {
            $query->whereNotIn('id', $touchedIds);
        }

        return $query->update([
            'status' => EnterpriseWikiLintFinding::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }

    /**
     * The observability the characterization test showed candidate discovery lacks: after a run it
     * must be answerable why a given page was or was not treated as inconsistent.
     *
     * @param  array<string, mixed>  $occurrence
     * @param  array<string, mixed>  $verdict
     * @param  array<string, mixed>|null  $finding
     */
    private function logOccurrence(EnterpriseWikiIngestRun $run, array $occurrence, array $verdict, ?array $finding): void
    {
        Log::info('[WIKI_CROSS_PAGE] Occurrence classified.', [
            'run_id' => $run->id,
            'page_id' => $occurrence['page_id'],
            'page_title' => $occurrence['page_title'],
            'page_type' => $occurrence['page_type'],
            'page_version_id' => $occurrence['page_version_id'],
            'topic' => $occurrence['topic'],
            'prefilter_signal' => $occurrence['prefilter_signal'],
            'old_present' => $occurrence['old_substance_present'],
            'new_present' => $occurrence['new_substance_present'],
            'new_state_present' => $occurrence['new_state_present'],
            'is_document_derived' => $occurrence['is_document_derived'],
            'is_patch_target' => $occurrence['is_patch_target'],
            'classification' => $verdict['classification'],
            'confidence' => $verdict['confidence'],
            'classified_by' => $verdict['source'],
            'finding' => $finding['code'] ?? 'none',
            'severity' => $finding['severity'] ?? 'none',
            'reason' => $verdict['reason'],
        ]);
    }
}
