<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;

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
 *  - target_heading, when given, is a heading that actually exists on the current version
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
    public function resolveForCustomer(int $customerId, array $decision): array
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

            $statedType = is_string($target['target_page_type'] ?? null) ? trim((string) $target['target_page_type']) : '';

            if ($statedType !== '' && $statedType !== $page->page_type) {
                $errors[] = "{$ctx}.target_page_type [{$statedType}] does not match the stored page type [{$page->page_type}] for page [{$pageId}] — "
                    ."the database is authoritative and a patch target never changes a page's type.";
            }

            $heading = is_string($target['target_heading'] ?? null) ? trim((string) $target['target_heading']) : '';

            if ($heading !== '' && ! $this->headingExists($heading, (string) $version->content_markdown)) {
                $errors[] = "{$ctx}.target_heading [{$heading}] is not a heading on the current version of page [{$pageId}] — "
                    .'name an existing heading or leave it null and rely on target_topic.';
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
     * Whether the markdown carries this ATX heading, comparing on the heading text alone and
     * ignoring level, surrounding whitespace and case. Deliberately a plain, level-agnostic text
     * comparison: this validates that the anchor the maintainer named is real, it does not attempt
     * to locate, bound or splice a section — that is 8K-3's work.
     */
    private function headingExists(string $heading, string $markdown): bool
    {
        $needle = $this->normalizeHeading($heading);

        if ($needle === '') {
            return false;
        }

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.*)$/u', (string) $line, $matches) !== 1) {
                continue;
            }

            if ($this->normalizeHeading($matches[1]) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHeading(string $value): string
    {
        // Strip a trailing closed-ATX run of #, collapse whitespace, casefold.
        $value = preg_replace('/\s+#+\s*$/u', '', trim($value)) ?? trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }
}
