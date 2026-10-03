<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiInvalidWikilinksException;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Facades\Log;

/**
 * Turns AI-selected, catalog-scoped page identities into canonical Wiki markdown.
 *
 * THE MODEL WRITES NO LINK SYNTAX. It returns prose, and a structured intent naming the target page
 * and the exact words in that prose the link belongs on. Everything with a delimiter in it — the
 * brackets, the pipe, the canonical slug — is written here.
 *
 * That division is the fix for run 59. The anchor and its placement used to be expressed by a
 * {{wiki_link:intent-id|visible anchor}} marker the model had to construct inside free text, where
 * no schema reaches: an anchor containing a pipe or a brace, or an intent id carrying a Norwegian
 * letter (the page was "Hendelseshåndtering (Incident Management)"), produced a token this class
 * could not parse, and an unparseable token failed the page and the whole run. The same value had
 * to be written twice — once in a schema-validated field and once as free text — and only one copy
 * was validated. A structured field cannot be malformed, so that failure class is gone rather than
 * guarded against.
 *
 * What is unchanged, deliberately: page identity is server-authoritative (target_page_id must be in
 * the run's catalog), the slug is always the stored canonical one, and unknown/self/cross-customer
 * targets are hard rejections that fail the page. An intent whose anchor cannot be found is DROPPED
 * with a log, never guessed at — the same treatment a valid intent with no marker already had.
 */
class EnterpriseWikiLinkIntentMaterializer
{
    /**
     * The retired marker syntax. Kept only as something to REJECT: raw internal syntax must never
     * reach a persisted page, whatever a model decides to write.
     */
    private const RETIRED_MARKER_PREFIX = '{{wiki_link:';

    public function __construct(
        private readonly EnterpriseWikiLinkParser $linkParser,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{page_id: int, slug: string, title: string, page_type: string}>  $catalog
     * @return list<array<string, mixed>>
     */
    public function materializeBlocks(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $sourcePage, array $blocks, array $catalog): array
    {
        return $this->materialize(
            [
                'customer_id' => (int) $run->customer_id,
                'run_id' => (int) $run->id,
                'source_page_id' => (int) $sourcePage->id,
                'source_page_type' => (string) $sourcePage->page_type,
            ],
            $blocks,
            $catalog,
        );
    }

    /**
     * The same materialization for content whose page does not exist yet.
     *
     * A draft written outside the ingest pipeline — Kvalitet drafting an article from a
     * prosessaktivitet — has no run and no page row to be the source of the link, but it must not
     * get its own link syntax for that reason: the model writing [[...]] itself is exactly the
     * failure class the intent contract removed. The guarantees that matter are unchanged — page
     * identity is server-authoritative, the slug is always the stored canonical one, and a target
     * outside the catalog or outside the customer is a hard rejection. Only the self-link check
     * drops out, because there is no self to link to yet.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{page_id: int, slug: string, title: string, page_type: string}>  $catalog
     * @return list<array<string, mixed>>
     */
    public function materializeBlocksForNewPage(int $customerId, array $blocks, array $catalog): array
    {
        return $this->materialize(
            [
                'customer_id' => $customerId,
                'run_id' => null,
                'source_page_id' => null,
                'source_page_type' => null,
            ],
            $blocks,
            $catalog,
        );
    }

    /**
     * @param  array{customer_id: int, run_id: ?int, source_page_id: ?int, source_page_type: ?string}  $context
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{page_id: int, slug: string, title: string, page_type: string}>  $catalog
     * @return list<array<string, mixed>>
     */
    private function materialize(array $context, array $blocks, array $catalog): array
    {
        $catalogByPageId = [];
        foreach ($catalog as $entry) {
            if (isset($entry['page_id']) && is_int($entry['page_id'])) {
                $catalogByPageId[$entry['page_id']] = $entry;
            }
        }

        foreach ($blocks as $blockIndex => $block) {
            $intents = $block['link_intents'] ?? [];
            $markdown = (string) ($block['markdown'] ?? '');

            if (! is_array($intents)) {
                $this->reject($context, null, null, 'rejected_invalid_intent', 'link_intents was not an array');
            }

            // No model may emit the retired marker syntax, with or without intents behind it.
            if (str_contains($markdown, self::RETIRED_MARKER_PREFIX)) {
                $this->reject($context, null, null, 'rejected_invalid_intent', 'markdown contained retired internal wikilink marker syntax');
            }

            if ($intents === []) {
                continue;
            }

            $resolvedIntents = $this->validateIntents($context, $intents, $catalogByPageId);

            // Model-authored [[...]] markup is neutralised BEFORE anchors are placed: its target is
            // discarded (only this class may choose a slug) and its visible text becomes ordinary
            // prose, which an intent may then legitimately anchor on.
            [$markdown, $legacyAnchors] = $this->replaceModelAuthoredWikilinksWithTokens($markdown, $blockIndex);
            $markdown = strtr($markdown, $legacyAnchors);

            $materializedIntents = [];

            foreach ($resolvedIntents as $intentId => $resolvedIntent) {
                $anchorText = trim((string) ($resolvedIntent['intent']['anchor_text'] ?? ''));

                if ($anchorText === '') {
                    $this->reject($context, $resolvedIntent['target_page']->id, $resolvedIntent['target_page']->slug, 'rejected_invalid_intent', 'link intent had no anchor_text');
                }

                $offset = $this->firstPlaceableOccurrence($markdown, $anchorText);

                if ($offset === null) {
                    // Not a safety failure: the target was valid, the words to carry it were not
                    // found in the prose. Dropping the link keeps the page truthful; inventing a
                    // position for it would not.
                    Log::info('[WIKI_LINK_MATERIALIZATION] Valid AI link intent was not materialized.', [
                        'run_id' => $context['run_id'],
                        'source_page_id' => $context['source_page_id'],
                        'selected_target_page_id' => $resolvedIntent['target_page']->id,
                        'canonical_target_slug' => $resolvedIntent['target_page']->slug,
                        'outcome' => 'skipped_anchor_not_found',
                    ]);

                    continue;
                }

                $canonical = "[[{$resolvedIntent['target_page']->slug}|{$anchorText}]]";
                $markdown = substr_replace($markdown, $canonical, $offset, strlen($anchorText));

                $materializedIntents[] = $resolvedIntent['intent'];
                $this->logMaterialized($context, $resolvedIntent['target_page']);
            }

            $blocks[$blockIndex]['markdown'] = $markdown;
            $blocks[$blockIndex]['link_intents'] = array_values($materializedIntents);
        }

        return $blocks;
    }

    /**
     * The first byte offset where $anchorText can be wrapped in a link without nesting it inside an
     * existing one.
     *
     * Existing link spans are recomputed from the CURRENT markdown on every call, so a link this
     * loop just inserted protects itself with no offset bookkeeping — and so does a plain Markdown
     * link the model wrote on its own. Without that, an anchor occurring inside `[Servicedesk](…)`
     * would be wrapped in place and produce `[[[slug|Servicedesk]](…)`: syntactically broken output
     * from a perfectly valid intent, which is the same class of failure this whole change removes.
     *
     * First occurrence, not "the only occurrence": which mention of a phrase carries the link is a
     * matter of convention (the first one), never of correctness — the target is authoritative
     * either way. Exact substring only; nothing here normalises case, whitespace or accents, so
     * "Hendelseshåndtering" never silently matches "hendelseshandtering".
     */
    private function firstPlaceableOccurrence(string $markdown, string $anchorText): ?int
    {
        $protected = $this->linkRanges($markdown);
        $offset = 0;

        while (($found = strpos($markdown, $anchorText, $offset)) !== false) {
            $end = $found + strlen($anchorText);
            $overlaps = false;

            foreach ($protected as [$start, $stop]) {
                if ($found < $stop && $end > $start) {
                    $overlaps = true;

                    break;
                }
            }

            if (! $overlaps) {
                return $found;
            }

            $offset = $found + 1;
        }

        return null;
    }

    /**
     * Byte ranges of every link construct in the markdown — canonical wikilinks and the ordinary
     * Markdown links a model sometimes writes anyway. Structural, not semantic: this only says
     * "these characters are already part of a link".
     *
     * @return list<array{0: int, 1: int}>
     */
    private function linkRanges(string $markdown): array
    {
        $ranges = [];

        foreach (['/\[\[[^\]]*\]\]/u', '/\[[^\]]*\]\([^)]*\)/u'] as $pattern) {
            if (preg_match_all($pattern, $markdown, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as [$text, $offset]) {
                $ranges[] = [$offset, $offset + strlen($text)];
            }
        }

        return $ranges;
    }

    /**
     * @param  array{customer_id: int, run_id: ?int, source_page_id: ?int, source_page_type: ?string}  $context
     * @param  list<array<string, mixed>>  $intents
     * @param  array<int, array{page_id: int, slug: string, title: string, page_type: string}>  $catalogByPageId
     * @return array<string, array{intent: array<string, mixed>, target_page: EnterpriseWikiPage}>
     */
    private function validateIntents(array $context, array $intents, array $catalogByPageId): array
    {
        $resolved = [];

        foreach ($intents as $intent) {
            if (! is_array($intent)) {
                $this->reject($context, null, null, 'rejected_invalid_intent', 'link intent was not an object');
            }

            $intentId = trim((string) ($intent['intent_id'] ?? ''));
            if ($intentId === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $intentId) || isset($resolved[$intentId])) {
                $this->reject($context, null, null, 'rejected_invalid_intent', 'intent_id was missing, malformed, or duplicated');
            }

            $targetPageId = $intent['target_page_id'] ?? null;
            if (! is_int($targetPageId)) {
                $this->reject($context, null, null, 'rejected_unknown_target', 'target_page_id was not an integer');
            }

            if ($context['source_page_id'] !== null && $targetPageId === $context['source_page_id']) {
                $this->reject($context, $targetPageId, null, 'rejected_self_link', 'target_page_id selected the source page');
            }

            $targetPage = EnterpriseWikiPage::query()->find($targetPageId);
            if ($targetPage !== null && (int) $targetPage->customer_id !== $context['customer_id']) {
                $this->reject($context, $targetPageId, $targetPage->slug, 'rejected_cross_customer', 'target page belongs to another customer');
            }

            if ($targetPage === null || ! array_key_exists($targetPageId, $catalogByPageId)) {
                $this->reject($context, $targetPageId, $targetPage?->slug, 'rejected_unknown_target', 'target page is not in the allowed catalog');
            }

            $resolved[$intentId] = [
                'intent' => $intent,
                'target_page' => $targetPage,
            ];
        }

        return $resolved;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function replaceModelAuthoredWikilinksWithTokens(string $markdown, int $blockIndex): array
    {
        $anchors = [];
        $replacements = [];

        foreach ($this->linkParser->parse($markdown) as $linkIndex => $link) {
            $token = "{{WIKI_LINK_LEGACY_{$blockIndex}_{$linkIndex}}}";
            $anchors[$token] = $link['anchor_text'];
            $replacements[$link['original_markup']] = $token;
        }

        return [$replacements === [] ? $markdown : strtr($markdown, $replacements), $anchors];
    }

    /**
     * @param  array{customer_id: int, run_id: ?int, source_page_id: ?int, source_page_type: ?string}  $context
     */
    private function logMaterialized(array $context, EnterpriseWikiPage $targetPage): void
    {
        Log::info('[WIKI_LINK_MATERIALIZATION] AI link intent materialized.', [
            'run_id' => $context['run_id'],
            'source_page_id' => $context['source_page_id'],
            'selected_target_page_id' => $targetPage->id,
            'canonical_target_slug' => $targetPage->slug,
            'outcome' => 'materialized',
        ]);
    }

    /**
     * @param  array{customer_id: int, run_id: ?int, source_page_id: ?int, source_page_type: ?string}  $context
     */
    private function reject(
        array $context,
        ?int $targetPageId,
        ?string $canonicalSlug,
        string $outcome,
        string $detail,
    ): never {
        Log::warning('[WIKI_LINK_MATERIALIZATION] AI link intent rejected.', [
            'run_id' => $context['run_id'],
            'source_page_id' => $context['source_page_id'],
            'selected_target_page_id' => $targetPageId,
            'canonical_target_slug' => $canonicalSlug,
            'outcome' => $outcome,
        ]);

        throw new EnterpriseWikiInvalidWikilinksException(sprintf(
            'Run [%s] page [%s] (%s): AI link intent rejected (%s: %s).',
            $context['run_id'] === null ? 'none' : (string) $context['run_id'],
            $context['source_page_id'] === null ? 'new' : (string) $context['source_page_id'],
            $context['source_page_type'] ?? 'unsaved',
            $outcome,
            $detail,
        ));
    }
}
