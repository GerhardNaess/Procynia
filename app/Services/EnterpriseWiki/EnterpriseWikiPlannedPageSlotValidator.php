<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiPage;

/**
 * The DB-authoritative half of the page-slot contract: an EXISTING page named by a decision must be
 * named through the slot that matches the type it already has.
 *
 * `concept_pages` and `entity_pages` are typed slots — the slot a page is written in is what
 * EnterpriseWikiMaintainerDecisionApplyService turns into `page_type`. That makes the slot a claim
 * about identity, and identity of an existing page belongs to the database, never to the decision.
 * Apply already refuses to retype a page named by an explicit page_id, and keeps doing so; the
 * problem run 55 exposed is that the refusal was the FIRST check anywhere: the decision was
 * validated, repaired, persisted, and pages were being created when it fired, so a whole run died on
 * something knowable the moment the decision existed.
 *
 * Two rules, both deterministic:
 *
 *  1. A page_id must resolve to a live page of THIS customer whose stored page_type equals the slot
 *     it is written in. This is the same statement apply makes, moved to where it can still be
 *     repaired cheaply.
 *  2. The same page_id must not appear in more than one slot entry. Run 55 had page 193 in
 *     `entity_pages` (correct — it is an entity) AND in `concept_pages` (a second, conflicting
 *     claim about the same identity). Even when one of them matches the stored type, two slots for
 *     one page is two different intentions for one row.
 *
 * Issues name the decision object the way EnterpriseWikiMaintainerDecisionObjectIndex does
 * (`concept_pages[0]`), so EnterpriseWikiMaintainerDecisionIssueAttributor scopes them structurally
 * and the bounded delta repair can drop or re-slot exactly that entry.
 *
 * Nothing here writes: page identity, type and existence are read from the database and reported.
 */
class EnterpriseWikiPlannedPageSlotValidator
{
    /** Decision slot => the page_type that slot means. */
    private const SLOT_PAGE_TYPES = [
        'concept_pages' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
        'entity_pages' => EnterpriseWikiPage::PAGE_TYPE_ENTITY,
    ];

    /**
     * @param  array<string, mixed>  $decision
     * @param  int  $customerId  0 means a caller with no tenant context (never the document flow):
     *                           the checks are skipped rather than inventing a failure from missing context, exactly as
     *                           EnterpriseWikiPatchTargetResolver does.
     * @return string[] Empty when every named page is addressed through its own type's slot.
     */
    public function findIssues(array $decision, int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $entries = $this->entriesWithPageId($decision);

        if ($entries === []) {
            return [];
        }

        $pages = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', array_values(array_unique(array_column($entries, 'page_id'))))
            ->get()
            ->keyBy('id');

        $issues = [];
        $seenPageIds = [];

        foreach ($entries as $entry) {
            $pageId = $entry['page_id'];
            $ctx = $entry['ctx'];
            $page = $pages->get($pageId);

            if ($page === null) {
                $issues[] = "{$ctx} names page_id [{$pageId}], which is not a page of this customer — "
                    .'reference only pages listed in the wiki index, or create the page instead (action "create", page_id null).';

                continue;
            }

            $expectedType = self::SLOT_PAGE_TYPES[$entry['slot']];

            if ($page->page_type !== $expectedType) {
                $issues[] = "{$ctx} targets existing page [{$pageId}] of type [{$page->page_type}] through a "
                    ."[{$expectedType}] slot — a page's type is never changed by the slot a decision names it in. "
                    .'Move it to the slot for its own type, or address it with a patch target instead.';

                continue;
            }

            if (isset($seenPageIds[$pageId])) {
                $issues[] = "{$ctx} names page [{$pageId}], which {$seenPageIds[$pageId]} already names — "
                    .'one existing page is addressed through exactly one slot in a decision.';

                continue;
            }

            $seenPageIds[$pageId] = $ctx;
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return list<array{ctx: string, slot: string, page_id: int}>
     */
    private function entriesWithPageId(array $decision): array
    {
        $entries = [];

        foreach (array_keys(self::SLOT_PAGE_TYPES) as $slot) {
            foreach ((array) ($decision[$slot] ?? []) as $index => $entry) {
                $pageId = is_array($entry) ? ($entry['page_id'] ?? null) : null;

                if (! is_int($pageId) || $pageId < 1) {
                    continue;
                }

                $title = trim((string) ($entry['title'] ?? ''));
                $ctx = "{$slot}[{$index}]".($title !== '' ? " (\"{$title}\")" : '');

                $entries[] = ['ctx' => $ctx, 'slot' => $slot, 'page_id' => $pageId];
            }
        }

        return $entries;
    }
}
