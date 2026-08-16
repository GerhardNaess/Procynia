<?php

namespace App\Services\EnterpriseWiki;

/**
 * Every owned topic must be bound to real source evidence — checked at DECISION time, against this
 * document's own element catalog.
 *
 * This is the missing half of a contract the rest of the pipeline already had. Generation receives
 * an authoritative per-section evidence contract (planned_topic + required_heading + assigned
 * source_element_keys) and is explicitly forbidden from using one section's evidence under another
 * — but nothing produced that contract. A planner wrote free-text topics, and a keyword heuristic
 * downstream tried to guess which elements each one meant. Run 53 is what that costs: five concept
 * pages whose planned sections ("Godkjenningstidspunkt", "Budsjett og kostnadsrammer", "Insentiver
 * og kostnadsdeling", "Fordeler og ulemper", "Avhengigheter og milepæler") could not be bound, each
 * discovered one expensive page-generation attempt at a time, and the whole run failed after the
 * planning phase had already succeeded.
 *
 * Two rules, both deterministic and both domain-free:
 *
 *  1. An owned topic names at least one source element key. A page may not promise to explain
 *     something this document does not support — that promise is precisely what generation cannot
 *     keep, and the cheapest place to discover it is here.
 *  2. Every named key exists in this document's catalog. Same rule, same wording and same
 *     reasoning as EnterpriseWikiCanonicalOwnershipValidator's check for patch targets: an invented
 *     or cross-document key would let a section claim grounding it does not have.
 *
 * Issues name the decision object the way EnterpriseWikiMaintainerDecisionObjectIndex does
 * (`concept_pages[3]`), so EnterpriseWikiMaintainerDecisionIssueAttributor scopes them structurally
 * and the existing bounded delta repair can fix them for a few hundred output tokens — instead of a
 * failed run and a full re-plan.
 *
 * Legacy decisions (plain-string owned_topics, stored before the binding existed) are never
 * retroactively flagged: same treatment as every other field this contract has grown.
 */
class EnterpriseWikiPlannedTopicEvidenceValidator
{
    /**
     * The decision slots whose owned topics become a page. All of them: an owned topic is a promise
     * to explain something, and that promise is equally ungrounded on a summary as on a concept
     * page.
     */
    private const PAGE_SLOTS = ['source_article', 'source_summary'];

    private const PAGE_LISTS = ['concept_pages', 'entity_pages'];

    /**
     * @param  array<string, mixed>  $decision
     * @param  string[]  $validSourceElementKeys  Every addressable key in this document's catalog.
     *                                            An empty list means the caller has no catalog to check against (a non-DOCX import, or a
     *                                            hand-built decision in a test): the "keys must exist" rule is skipped, exactly as the
     *                                            patch-target rule does, rather than inventing a failure from missing context.
     * @return string[] Empty when every owned topic is bound to real evidence.
     */
    public function findIssues(array $decision, array $validSourceElementKeys = []): array
    {
        $known = array_flip(array_map('strval', $validSourceElementKeys));
        $issues = [];

        foreach (self::PAGE_SLOTS as $slot) {
            $entry = $decision[$slot] ?? null;

            if (is_array($entry)) {
                $issues = array_merge($issues, $this->issuesForPage($entry, $slot, $known));
            }
        }

        foreach (self::PAGE_LISTS as $list) {
            foreach ((array) ($decision[$list] ?? []) as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $ctx = "{$list}[{$index}]";
                $issues = array_merge($issues, $this->issuesForPage($entry, $ctx, $known));

                if ($list === 'concept_pages') {
                    $issues = array_merge($issues, $this->issuesForEmptyScope($entry, $ctx));
                }
            }
        }

        return $issues;
    }

    /**
     * A concept page created by this decision must own at least one topic.
     *
     * Not a style rule — it closes the only way around rule 1. Told to bind every owned topic to
     * source evidence, a planner can satisfy the letter of that by owning nothing at all, and the
     * first runtime verification of this contract showed it doing exactly that: 14 concept pages,
     * zero owned topics between them, every one of them a page with no scope for generation to
     * write against. It is also precisely what the existing create-gate already implies — a page is
     * only created when "the source gives enough actual content to write a meaningful page about the
     * concept itself", which is one evidence-bound topic by definition.
     *
     * Deliberately limited to CREATED concept pages: an `update` may legitimately add nothing to a
     * page's scope, and entity pages have always been allowed to carry no owned topics (see
     * FinalizeEnterpriseWikiPageGeneration's generation waves).
     *
     * @param  array<string, mixed>  $page
     * @return string[]
     */
    private function issuesForEmptyScope(array $page, string $ctx): array
    {
        if (($page['action'] ?? 'create') !== 'create') {
            return [];
        }

        $topics = array_values(array_filter(
            (array) ($page['owned_topics'] ?? []),
            static fn (mixed $item): bool => (is_string($item) && trim($item) !== '')
                || (is_array($item) && trim((string) ($item['topic'] ?? '')) !== ''),
        ));

        if ($topics !== []) {
            return [];
        }

        $title = trim((string) ($page['title'] ?? ''));

        return ["{$ctx}".($title !== '' ? " (\"{$title}\")" : '').' is created without owning a single topic — '
            .'a new concept page must own at least one evidence-bound topic, or it has no scope to be '
            .'written against. Give it the topic(s) it explains with the source elements they rest on, or '
            .'do not create the page at all (decide "reference_only" or "exclude" for its candidate).'];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, int>  $known
     * @return string[]
     */
    private function issuesForPage(array $page, string $ctx, array $known): array
    {
        $issues = [];
        $title = trim((string) ($page['title'] ?? ''));
        $label = $title !== '' ? "{$ctx} (\"{$title}\")" : $ctx;

        foreach ((array) ($page['owned_topics'] ?? []) as $item) {
            // A legacy plain-string topic carries no binding to check. Never retroactively flagged.
            if (! is_array($item)) {
                continue;
            }

            $topic = trim((string) ($item['topic'] ?? ''));

            if ($topic === '') {
                continue;
            }

            $keys = array_values(array_filter(array_map(
                static fn (mixed $key): string => is_string($key) ? trim($key) : '',
                (array) ($item['source_element_keys'] ?? []),
            ), static fn (string $key): bool => $key !== ''));

            if ($keys === []) {
                $issues[] = "{$label} owns topic [{$topic}] without naming any source element that supports it — "
                    .'either name the SOURCE ELEMENTS keys this page will explain the topic from, or drop the topic '
                    .'(move it to reference_only_topics or excluded_topics). A page may not promise to explain '
                    .'something this document does not cover.';

                continue;
            }

            if ($known === []) {
                continue;
            }

            $unknown = array_values(array_filter($keys, static fn (string $key): bool => ! array_key_exists($key, $known)));

            if ($unknown !== []) {
                $issues[] = "{$label} owns topic [{$topic}] citing source element key(s) ["
                    .implode(', ', $unknown).'] that are not in this document\'s source catalog — '
                    .'reference only keys the catalog lists, and never invent one.';
            }
        }

        return $issues;
    }
}
