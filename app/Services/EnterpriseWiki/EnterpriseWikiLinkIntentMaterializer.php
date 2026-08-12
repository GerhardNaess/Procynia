<?php

namespace App\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiInvalidWikilinksException;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use Illuminate\Support\Facades\Log;

/**
 * Turns AI-selected, catalog-scoped page identities into canonical Wiki markdown.
 *
 * A link's target is selected only in structured output. Its visible anchor and placement are
 * written once as {{wiki_link:intent-id|visible anchor}} in the block markdown, so the backend
 * never has to guess whether a separately returned anchor string matches the generated prose.
 */
class EnterpriseWikiLinkIntentMaterializer
{
    private const MARKER_PREFIX = '{{wiki_link:';

    private const MARKER_PATTERN = '/\{\{wiki_link:([A-Za-z0-9_-]+)\|([^{}|]+)\}\}/u';

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
                $this->reject($run, $sourcePage, null, null, 'rejected_invalid_intent', 'link_intents was not an array');
            }

            if ($intents === []) {
                if (str_contains($markdown, self::MARKER_PREFIX)) {
                    $this->reject($run, $sourcePage, null, null, 'rejected_invalid_intent', 'wikilink marker had no structured link intent');
                }

                continue;
            }

            $resolvedIntents = $this->validateIntents($run, $sourcePage, $intents, $catalogByPageId);
            [$markdown, $legacyAnchors] = $this->replaceModelAuthoredWikilinksWithTokens($markdown, $blockIndex);
            $markerCount = preg_match_all(self::MARKER_PATTERN, $markdown, $markerMatches, PREG_SET_ORDER);

            if ($markerCount === false || substr_count($markdown, self::MARKER_PREFIX) !== $markerCount) {
                $this->reject($run, $sourcePage, null, null, 'rejected_invalid_intent', 'wikilink marker syntax was malformed');
            }

            $usedIntentIds = [];
            $replacements = [];
            $materializedIntents = [];

            foreach ($markerMatches as $marker) {
                $intentId = $marker[1];
                $anchorText = trim($marker[2]);

                if ($anchorText === '' || ! isset($resolvedIntents[$intentId])) {
                    $this->reject($run, $sourcePage, null, null, 'rejected_invalid_intent', 'wikilink marker did not match a structured link intent');
                }

                if (isset($usedIntentIds[$intentId])) {
                    $this->reject($run, $sourcePage, null, null, 'rejected_invalid_intent', 'structured link intent appeared in more than one marker');
                }

                $resolvedIntent = $resolvedIntents[$intentId];
                $replacements[$marker[0]] = "[[{$resolvedIntent['target_page']->slug}|{$anchorText}]]";
                $usedIntentIds[$intentId] = true;
                $materializedIntents[] = $resolvedIntent['intent'];
                $this->logMaterialized($run, $sourcePage, $resolvedIntent['target_page']);
            }

            // One legacy free Markdown link remains a narrowly compatible anchor source for one
            // otherwise valid intent. Its target slug is discarded completely; only the visible
            // anchor is reused, so the structured target_page_id remains authoritative.
            $unmaterializedIntentIds = array_values(array_diff(array_keys($resolvedIntents), array_keys($usedIntentIds)));
            if (count($unmaterializedIntentIds) === 1 && count($legacyAnchors) === 1) {
                $intentId = $unmaterializedIntentIds[0];
                $resolvedIntent = $resolvedIntents[$intentId];
                $legacyToken = array_key_first($legacyAnchors);
                $anchorText = $legacyAnchors[$legacyToken];
                $replacements[$legacyToken] = "[[{$resolvedIntent['target_page']->slug}|{$anchorText}]]";
                $usedIntentIds[$intentId] = true;
                $materializedIntents[] = $resolvedIntent['intent'];
                $this->logMaterialized($run, $sourcePage, $resolvedIntent['target_page']);
            }

            foreach ($legacyAnchors as $legacyToken => $anchorText) {
                $replacements[$legacyToken] ??= $anchorText;
            }

            foreach (array_keys($resolvedIntents) as $intentId) {
                if (isset($usedIntentIds[$intentId])) {
                    continue;
                }

                Log::info('[WIKI_LINK_MATERIALIZATION] Valid AI link intent was not materialized.', [
                    'run_id' => $run->id,
                    'source_page_id' => $sourcePage->id,
                    'selected_target_page_id' => $resolvedIntents[$intentId]['target_page']->id,
                    'canonical_target_slug' => $resolvedIntents[$intentId]['target_page']->slug,
                    'outcome' => 'skipped_missing_marker',
                ]);
            }

            $blocks[$blockIndex]['markdown'] = strtr($markdown, $replacements);
            $blocks[$blockIndex]['link_intents'] = array_values($materializedIntents);
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $intents
     * @param  array<int, array{page_id: int, slug: string, title: string, page_type: string}>  $catalogByPageId
     * @return array<string, array{intent: array<string, mixed>, target_page: EnterpriseWikiPage}>
     */
    private function validateIntents(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $sourcePage, array $intents, array $catalogByPageId): array
    {
        $resolved = [];

        foreach ($intents as $intent) {
            if (! is_array($intent)) {
                $this->reject($run, $sourcePage, null, null, 'rejected_invalid_intent', 'link intent was not an object');
            }

            $intentId = trim((string) ($intent['intent_id'] ?? ''));
            if ($intentId === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $intentId) || isset($resolved[$intentId])) {
                $this->reject($run, $sourcePage, null, null, 'rejected_invalid_intent', 'intent_id was missing, malformed, or duplicated');
            }

            $targetPageId = $intent['target_page_id'] ?? null;
            if (! is_int($targetPageId)) {
                $this->reject($run, $sourcePage, null, null, 'rejected_unknown_target', 'target_page_id was not an integer');
            }

            if ($targetPageId === $sourcePage->id) {
                $this->reject($run, $sourcePage, $targetPageId, null, 'rejected_self_link', 'target_page_id selected the source page');
            }

            $targetPage = EnterpriseWikiPage::query()->find($targetPageId);
            if ($targetPage !== null && $targetPage->customer_id !== $run->customer_id) {
                $this->reject($run, $sourcePage, $targetPageId, $targetPage->slug, 'rejected_cross_customer', 'target page belongs to another customer');
            }

            if ($targetPage === null || ! array_key_exists($targetPageId, $catalogByPageId)) {
                $this->reject($run, $sourcePage, $targetPageId, $targetPage?->slug, 'rejected_unknown_target', 'target page is not in the allowed catalog');
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

    private function logMaterialized(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $sourcePage, EnterpriseWikiPage $targetPage): void
    {
        Log::info('[WIKI_LINK_MATERIALIZATION] AI link intent materialized.', [
            'run_id' => $run->id,
            'source_page_id' => $sourcePage->id,
            'selected_target_page_id' => $targetPage->id,
            'canonical_target_slug' => $targetPage->slug,
            'outcome' => 'materialized',
        ]);
    }

    private function reject(
        EnterpriseWikiIngestRun $run,
        EnterpriseWikiPage $sourcePage,
        ?int $targetPageId,
        ?string $canonicalSlug,
        string $outcome,
        string $detail,
    ): never {
        Log::warning('[WIKI_LINK_MATERIALIZATION] AI link intent rejected.', [
            'run_id' => $run->id,
            'source_page_id' => $sourcePage->id,
            'selected_target_page_id' => $targetPageId,
            'canonical_target_slug' => $canonicalSlug,
            'outcome' => $outcome,
        ]);

        throw new EnterpriseWikiInvalidWikilinksException(sprintf(
            'Run [%d] page [%d] (%s): AI link intent rejected (%s: %s).',
            $run->id,
            $sourcePage->id,
            $sourcePage->page_type,
            $outcome,
            $detail,
        ));
    }
}
