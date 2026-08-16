<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiPatchApplicationException;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Support\Facades\Log;

/**
 * Fase 8K-2: resolves the patch targets in a maintainer decision against the database, and reports
 * every target that cannot be trusted.
 *
 * This is the DB-aware half of patch-target validation. EnterpriseWikiMaintainerDecisionPrompt
 * checks a target's shape and its internal operation/substance coherence; this class checks the
 * things only the database knows:
 *
 *  - the target page exists
 *  - it belongs to THIS customer (a cross-customer page id is a tenancy violation, never a typo)
 *  - it is live Wiki knowledge, not archived/superseded/rejected
 *  - it has a current version at all — there is nothing to patch otherwise
 *  - its real page_type, read from the row and never taken from the model
 *  - the target area can be located at all — by heading, or as the body of a page that has no
 *    sub-sections (see EnterpriseWikiPatchSectionResolver, which this shares with the patch engine)
 *  - for a `replace`, the superseded_substance is present VERBATIM inside that area
 *
 * The last two use the same resolver the patch engine uses at apply time, deliberately: run 28 showed
 * what happens when validation and execution answer "where is this, and is the old text there?"
 * differently — a decision validated, was persisted, and only failed later inside the engine, long
 * after the bounded repair pass that could have fixed it.
 *
 * The page_type contract is the important one. The model states `target_page_type` so its belief is
 * visible and checkable, but the row is the only authority: a mismatch is reported as an error and
 * the stated value is discarded. Nothing in the 8K-2 path writes page_type, title or slug — a patch
 * target names a page by id, never by slot, so no page can be retyped by targeting it (which is
 * exactly what putting an article into `entity_pages` would have done via
 * EnterpriseWikiMaintainerDecisionApplyService::syncReusedPage()).
 *
 * Read-only. Resolving a target writes nothing and generates nothing.
 */
class EnterpriseWikiPatchTargetResolver
{
    /** Statuses that are not live Wiki knowledge and can therefore never be patched. */
    private const UNPATCHABLE_STATUSES = [
        EnterpriseWikiPage::STATUS_ARCHIVED,
        EnterpriseWikiPage::STATUS_SUPERSEDED,
        EnterpriseWikiPage::STATUS_REJECTED,
    ];

    /** Ceiling for echoing back the maintainer's OWN short values in an issue. */
    private const ISSUE_EXCERPT_CHARS = 400;

    /**
     * A resolved target area up to this size is shown to the repair pass IN FULL.
     *
     * Measured against the real pages this operates on rather than guessed: the areas involved in
     * run 29 flatten to 1634, 1589 and 2002 characters, and the other sections of those pages to
     * 495–644. 2500 therefore shows every observed area complete, with margin, while still bounding
     * what a repair prompt can grow to.
     */
    private const AREA_CONTEXT_CHARS = 2500;

    /**
     * Window size when an area exceeds AREA_CONTEXT_CHARS: the repair pass sees this much text
     * centred on the part of the area that best matches the substance it must correct.
     */
    private const AREA_CONTEXT_WINDOW_CHARS = 1400;

    /** A one-token anchor shorter than this is too generic to centre a window on. */
    private const ANCHOR_MIN_CHARS = 4;

    /** Bounds the anchor search on pathological input. A substance is a sentence, not a document. */
    private const ANCHOR_MAX_TOKENS = 40;

    public function __construct(
        private readonly EnterpriseWikiPatchSectionResolver $sectionResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $decision
     * @return array{
     *     resolved: list<array{
     *         index: int,
     *         target_page_id: int,
     *         page_type: string,
     *         title: string,
     *         slug: string,
     *         page_version_id: int,
     *         version_number: int,
     *         operation: string,
     *         relationship: string,
     *         target_topic: string,
     *         target_heading: string|null,
     *     }>,
     *     errors: string[],
     * }
     */
    public function resolveForCustomer(int $customerId, array $decision, ?int $runId = null): array
    {
        $targets = $decision['patch_targets'] ?? [];

        if (! is_array($targets) || $targets === []) {
            return ['resolved' => [], 'errors' => []];
        }

        $pageIds = [];

        foreach ($targets as $target) {
            $pageId = is_array($target) ? ($target['target_page_id'] ?? null) : null;

            if (is_int($pageId) && $pageId > 0) {
                $pageIds[] = $pageId;
            }
        }

        // Deliberately NOT customer-scoped: a page id belonging to another customer must be
        // reported as a cross-customer violation, which is impossible to distinguish from a
        // non-existent id if the query hides it. The customer check is explicit below, and no
        // other customer's data is ever returned to a caller.
        $pages = EnterpriseWikiPage::query()
            ->whereIn('id', array_values(array_unique($pageIds)))
            ->get(['id', 'customer_id', 'title', 'slug', 'page_type', 'status'])
            ->keyBy('id');

        $currentVersions = EnterpriseWikiPageVersion::query()
            ->whereIn('enterprise_wiki_page_id', $pages->pluck('id')->all())
            ->where('is_current', true)
            ->get(['id', 'enterprise_wiki_page_id', 'version_number', 'content_markdown'])
            ->keyBy('enterprise_wiki_page_id');

        $resolved = [];
        $errors = [];

        foreach ($targets as $i => $target) {
            $ctx = "patch_targets[{$i}]";

            if (! is_array($target)) {
                continue;
            }

            $pageId = $target['target_page_id'] ?? null;

            if (! is_int($pageId) || $pageId < 1) {
                continue;
            }

            $page = $pages->get($pageId);

            if (! $page instanceof EnterpriseWikiPage) {
                $errors[] = "{$ctx}.target_page_id [{$pageId}] does not exist.";

                continue;
            }

            if ((int) $page->customer_id !== $customerId) {
                $errors[] = "{$ctx}.target_page_id [{$pageId}] belongs to another customer — a patch target must be a page of customer [{$customerId}].";

                continue;
            }

            if (in_array($page->status, self::UNPATCHABLE_STATUSES, true)) {
                $errors[] = "{$ctx}.target_page_id [{$pageId}] has status [{$page->status}] and is not live Wiki knowledge — it cannot be a patch target.";

                continue;
            }

            $version = $currentVersions->get($page->id);

            if (! $version instanceof EnterpriseWikiPageVersion) {
                $errors[] = "{$ctx}.target_page_id [{$pageId}] has no current version — there is no authoritative content to patch.";

                continue;
            }

            $validTargetHeadings = EnterpriseWikiPatchSectionResolver::sectionHeadingsFromMarkdown((string) $version->content_markdown);
            $pageHasSubsections = $validTargetHeadings !== [];

            $statedType = is_string($target['target_page_type'] ?? null) ? trim((string) $target['target_page_type']) : '';

            if ($statedType !== '' && $statedType !== $page->page_type) {
                $errors[] = "{$ctx}.target_page_type [{$statedType}] does not match the stored page type [{$page->page_type}] for page [{$pageId}] — "
                    ."the database is authoritative and a patch target never changes a page's type.";
            }

            $heading = is_string($target['target_heading'] ?? null) ? trim((string) $target['target_heading']) : '';

            // Area resolution and superseded-substance verification use the SAME resolver the patch
            // engine will use at apply time. Validating with a second, near-identical implementation
            // is how a decision passes here and fails there — which is exactly what run 28 did.
            $areaError = $this->verifyTargetArea(
                $target,
                $version,
                $heading,
                $pageId,
                (string) $page->title,
                $validTargetHeadings,
                $pageHasSubsections,
                $ctx,
                $runId,
            );

            if ($areaError !== null) {
                $errors[] = $areaError;
            }

            $resolved[] = [
                'index' => (int) $i,
                'target_page_id' => (int) $page->id,
                // Read from the row, never from the decision.
                'page_type' => (string) $page->page_type,
                'title' => (string) $page->title,
                'slug' => (string) $page->slug,
                'page_version_id' => (int) $version->id,
                'version_number' => (int) $version->version_number,
                'operation' => (string) ($target['operation'] ?? ''),
                'relationship' => (string) ($target['relationship'] ?? ''),
                'target_topic' => trim((string) ($target['target_topic'] ?? '')),
                'target_heading' => $heading === '' ? null : $heading,
            ];
        }

        return ['resolved' => $resolved, 'errors' => $errors];
    }

    /**
     * The page ids a decision names as patch targets, whether or not they resolve. Used by the
     * apply layer and the generation guard, which must treat a page as patch-intended even when
     * the target itself turned out to be invalid — a broken target is a reason to refuse to touch
     * the page, never a reason to fall back to regenerating it.
     *
     * @param  array<string, mixed>  $decision
     * @return list<int>
     */
    public static function targetPageIds(array $decision): array
    {
        $ids = [];

        foreach ((array) ($decision['patch_targets'] ?? []) as $target) {
            if (! is_array($target)) {
                continue;
            }

            $pageId = $target['target_page_id'] ?? null;

            if (is_int($pageId) && $pageId > 0) {
                $ids[$pageId] = $pageId;
            }
        }

        return array_values($ids);
    }

    /**
     * Verify, at DECISION time, the two things that decide whether a patch can actually be carried
     * out: that the target area can be located, and — for a `replace` — that the substance it claims
     * to supersede is genuinely there.
     *
     * Run 28 is why this exists. A decision named a heading that existed, so it validated, was
     * persisted, and only failed hours later inside the patch engine because the maintainer had quoted
     * a clause as a whole sentence: it wrote "… innen 30 minutter." while the page says
     * "… innen 30 minutter, driftsleder skal varsle …". By then the bounded repair pass was long over,
     * and the failure took nine otherwise-valid targets on that page down with it.
     *
     * Checking here instead means the same mistake becomes an ordinary validation issue, repairable by
     * the pass that already exists. The patch engine stays strict on purpose — it must never decide
     * that a comma and a full stop "probably mean the same thing" — so the fix belongs in the
     * decision, not in the mutation.
     *
     * @param  array<string, mixed>  $target
     * @return string|null one issue, or null when the target is sound
     */
    private function verifyTargetArea(
        array $target,
        EnterpriseWikiPageVersion $version,
        string $heading,
        int $pageId,
        string $pageTitle,
        array $validTargetHeadings,
        bool $pageHasSubsections,
        string $ctx,
        ?int $runId = null,
    ): ?string {
        $blocks = $this->blocksFor($version);

        if ($blocks === []) {
            return null; // nothing to locate against; an empty version is reported elsewhere.
        }

        $topic = trim((string) ($target['target_topic'] ?? ''));

        try {
            $area = $this->sectionResolver->resolve($blocks, $heading === '' ? null : $heading, $topic, $ctx);
        } catch (EnterpriseWikiPatchApplicationException $e) {
            $issueCode = $heading !== '' ? 'invalid_target_heading' : 'missing_target_heading';

            if ($runId !== null) {
                Log::warning('[WIKI_MAINTAINER_DECISION] Invalid patch target heading detected.', [
                    'run_id' => $runId,
                    'target_page_id' => $pageId,
                    'target_page_title' => $pageTitle,
                    'current_version_id' => $version->id,
                    'invalid_heading' => $heading !== '' ? $heading : null,
                    'page_has_subsections' => $pageHasSubsections,
                    'valid_heading_count' => count($validTargetHeadings),
                    'issue_code' => $issueCode,
                ]);
            }

            $structuredContext = sprintf(
                'issue_code=%s; target_page_id=%d; target_page_title=[%s]; current_version_id=%d; page_has_subsections=%s; valid_target_headings=%s',
                $issueCode,
                $pageId,
                $pageTitle,
                (int) $version->id,
                $pageHasSubsections ? 'true' : 'false',
                (string) (json_encode(array_values($validTargetHeadings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'),
            );

            return $heading !== ''
                ? "{$structuredContext}; {$ctx}.target_heading [{$heading}] is not a heading on the current version of page [{$pageId}] — "
                    .'name one of the valid_target_headings, or leave it null only when the page has no sub-sections.'
                : "{$structuredContext}; {$ctx} names no target_heading and target_topic [{$topic}] does not identify a section of page [{$pageId}], "
                    .'which does have sub-sections — name one of the valid_target_headings.';
        }

        if ((string) ($target['operation'] ?? '') !== 'replace') {
            return null; // amend adds; preserve mutates nothing. Neither claims existing substance.
        }

        $superseded = trim((string) ($target['superseded_substance'] ?? ''));

        if ($superseded === '') {
            return null; // schema validation already reports a missing superseded_substance.
        }

        $areaText = $this->areaText($blocks, $area);

        $occurrences = mb_substr_count($areaText, $superseded);

        if ($occurrences === 1) {
            return null;
        }

        if ($occurrences > 1) {
            return "{$ctx}.superseded_substance occurs [{$occurrences}] times in the target area on page [{$pageId}]. "
                .'A replace target must identify exactly one occurrence; narrow the exact substring or split the target by heading. '
                .'Do not guess which occurrence to mutate.';
        }

        $where = $heading !== '' ? "under heading [{$heading}]" : 'in the page body (this page has no sub-sections)';

        return "{$ctx}.superseded_substance is not present verbatim {$where} on page [{$pageId}]. "
            ."Given: [{$this->excerpt($superseded)}]. "
            ."The relevant target area currently states: [{$this->areaContext($areaText, $superseded)}]. "
            .'Correct superseded_substance by copying an EXACT substring out of that text — character for character, '
            .'including its punctuation. It does not have to be a whole sentence; it has to be text that occurs there '
            .'exactly, and be specific enough to identify the substance being replaced. Copy only what this document '
            .'supersedes: the server preserves the surrounding text itself and keeps it attributed to its original '
            .'source. Do not paraphrase, do not shorten a clause into a sentence, and do not use wording from a '
            .'different page or a different target. Do not drop the patch target, do not move the finding to '
            .'warnings, do not change the relationship, and do not turn the replace into a create.';
    }

    /**
     * The text the repair pass is shown so it can copy from it.
     *
     * Run 29 is why this is not simply "the first N characters". The repair pass was told to copy
     * `superseded_substance` verbatim out of the target area and was shown the area's opening 400
     * characters — while the sentence it needed sat 537, 658 and 1077 characters past that cut on the
     * three failing targets. It could not follow the instruction, so it paraphrased, and on two
     * targets it reached for wording it COULD see: another target's page. The two targets whose text
     * happened to fall inside the window were repaired correctly. The correlation was exact.
     *
     * So the rule is: show the whole area when it is small enough (which covers every area observed
     * on these pages), and otherwise show a window centred on the part of the area that best matches
     * what the maintainer wrote.
     *
     * THE ANCHOR SEARCH IS FOR LOCATING CONTEXT ONLY. It never decides whether a patch is valid and
     * never selects text to mutate — verifyTargetArea() above still demands an exact substring, and
     * EnterpriseWikiPatchApplicationService still replaces only an exact substring. Nothing here can
     * make an inexact patch acceptable; it only decides which part of the page the model gets to read.
     */
    private function areaContext(string $areaText, string $superseded): string
    {
        $flat = $this->flatten($areaText);

        if (mb_strlen($flat) <= self::AREA_CONTEXT_CHARS) {
            return $flat;
        }

        $anchor = $this->anchorPosition($flat, $superseded);
        $half = intdiv(self::AREA_CONTEXT_WINDOW_CHARS, 2);

        // No usable anchor: deterministic fallback to the start of the area, as before.
        $start = $anchor === null ? 0 : max(0, $anchor - $half);
        $window = mb_substr($flat, $start, self::AREA_CONTEXT_WINDOW_CHARS);

        return ($start > 0 ? '...' : '')
            .$window
            .($start + self::AREA_CONTEXT_WINDOW_CHARS < mb_strlen($flat) ? '...' : '');
    }

    /**
     * Where in the area the maintainer's substance most nearly occurs — the longest run of its own
     * words that appears there exactly, leftmost when several runs tie.
     *
     * Deliberately generic: it compares the maintainer's own words against the page's own words and
     * knows nothing about languages, units, numbers or subject matter. A near-miss quote ("… 30
     * minutter." for "… 30 minutter, driftsleder …") shares a long run and lands exactly on the right
     * sentence; a wholesale paraphrase shares less and lands approximately; nothing at all returns
     * null and the fallback applies.
     *
     * Deterministic: fixed search order, so identical input always yields the same window.
     */
    private function anchorPosition(string $areaText, string $superseded): ?int
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', $this->flatten($superseded)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));

        $count = min(count($tokens), self::ANCHOR_MAX_TOKENS);

        for ($length = $count; $length >= 1; $length--) {
            for ($start = 0; $start + $length <= $count; $start++) {
                $needle = implode(' ', array_slice($tokens, $start, $length));

                if (mb_strlen($needle) < self::ANCHOR_MIN_CHARS) {
                    continue;
                }

                $position = mb_strpos($areaText, $needle);

                if ($position !== false) {
                    return $position;
                }
            }
        }

        return null;
    }

    private function flatten(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
    }

    /**
     * The version's blocks for area resolution.
     *
     * Normally this is `content_blocks_json`. When a version carries none — a legacy row, or a version
     * written before block metadata existed — the blocks are derived from `content_markdown` using the
     * same `\n{2,}` split that defines the relationship between the two representations
     * (EnterpriseWikiPageContentBlockService::buildBlocks()). Validation must still be able to answer
     * "does this heading exist, and is the old text under it" for such a version; deriving keeps that
     * working without claiming the derived blocks are real provenance-bearing blocks.
     *
     * @return list<array<string, mixed>>
     */
    private function blocksFor(EnterpriseWikiPageVersion $version): array
    {
        $blocks = [];

        foreach ((array) ($version->content_blocks_json ?? []) as $block) {
            if (is_array($block) && trim((string) ($block['markdown'] ?? '')) !== '') {
                $blocks[] = $block;
            }
        }

        if ($blocks !== []) {
            return $blocks;
        }

        foreach (preg_split("/\n{2,}/", trim((string) $version->content_markdown)) ?: [] as $part) {
            if (trim((string) $part) !== '') {
                $blocks[] = ['markdown' => trim((string) $part)];
            }
        }

        return $blocks;
    }

    /**
     * The text of the resolved area, as the patch engine will see it.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $area
     */
    private function areaText(array $blocks, array $area): string
    {
        $parts = [];

        for ($i = (int) $area['start_index']; $i <= (int) $area['end_index']; $i++) {
            $parts[] = $this->sectionResolver->inSectionText($area, $i, (string) ($blocks[$i]['markdown'] ?? ''));
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Bounded excerpt for an issue message — enough for the repair pass to see the real wording,
     * never enough to blow up the repair prompt.
     */
    private function excerpt(string $value): string
    {
        $value = $this->flatten($value);

        return mb_strlen($value) > self::ISSUE_EXCERPT_CHARS
            ? mb_substr($value, 0, self::ISSUE_EXCERPT_CHARS - 3).'...'
            : $value;
    }
}
