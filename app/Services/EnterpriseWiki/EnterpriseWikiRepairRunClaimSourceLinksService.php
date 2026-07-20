<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use Illuminate\Support\Facades\DB;

/**
 * Run-38 follow-up: repairs claims whose content_block_key is missing or stale relative to the
 * CURRENT page version, and restores the EnterpriseWikiSourceReference rows a correctly-linked
 * claim would have — without regenerating any page content and without calling AI.
 *
 * Deliberately scoped to an explicit list of claim IDs (never "every unsupported claim in the
 * run") — this is a targeted data repair for specific, already-investigated claims, not a
 * general-purpose sweep. A future caller who wants broader coverage should investigate and pass
 * a new explicit list, not widen this service's own scope.
 *
 * Matching rule (mirrors EnterpriseWikiClaimIntegrityRepairService::findUniqueBlockForClaim(),
 * but reuses the more tolerant EnterpriseWikiClaimAnchorTextNormalizer instead of a bare
 * substring check, since that normalizer already resolves the wikilink/whitespace/punctuation
 * artifacts real run-38 anchors contain): a claim's anchor (page_excerpt, falling back to
 * claim_text) must be a substring of EXACTLY ONE block's markdown in the claim's current page
 * version. Zero matches or more than one match is reported as unresolved — never guessed.
 *
 * Idempotent: re-running with the same claim IDs never creates duplicate source references (keyed
 * on claim_id + source_element_key) and never re-reports a claim whose content_block_key already
 * matches its unique block with all of that block's source elements already present.
 */
class EnterpriseWikiRepairRunClaimSourceLinksService
{
    public function __construct(
        private readonly EnterpriseWikiClaimAnchorTextNormalizer $textNormalizer,
    ) {}

    /**
     * @param  list<int>  $claimIds
     * @return array{results: list<array<string, mixed>>, relinked: int, references_created: int, ambiguous: int, no_match: int, unchanged: int, not_found: int}
     */
    public function repair(EnterpriseWikiIngestRun $run, array $claimIds, bool $apply): array
    {
        $pageIds = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->pluck('enterprise_wiki_page_id');

        $results = [];
        $counts = ['relinked' => 0, 'references_created' => 0, 'ambiguous' => 0, 'no_match' => 0, 'unchanged' => 0, 'not_found' => 0];

        foreach (array_unique($claimIds) as $claimId) {
            $claim = EnterpriseWikiClaim::query()->find($claimId);

            if ($claim === null || ! $pageIds->contains($claim->enterprise_wiki_page_id)) {
                $counts['not_found']++;
                $results[] = ['claim_id' => $claimId, 'status' => 'not_found_in_run'];

                continue;
            }

            $version = EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $claim->enterprise_wiki_page_id)
                ->where('is_current', true)
                ->first();

            if ($version === null) {
                $counts['no_match']++;
                $results[] = ['claim_id' => $claimId, 'status' => 'no_current_page_version'];

                continue;
            }

            $anchor = trim((string) ($claim->page_excerpt ?: $claim->claim_text));
            $blocks = (array) ($version->content_blocks_json ?? []);
            $matches = [];

            foreach ($blocks as $block) {
                if (is_array($block) && $anchor !== '' && $this->textNormalizer->contains((string) ($block['markdown'] ?? ''), $anchor)) {
                    $matches[] = $block;
                }
            }

            if ($matches === []) {
                $counts['no_match']++;
                $results[] = ['claim_id' => $claimId, 'page_id' => $claim->enterprise_wiki_page_id, 'status' => 'no_match'];

                continue;
            }

            if (count($matches) > 1) {
                $counts['ambiguous']++;
                $results[] = [
                    'claim_id' => $claimId,
                    'page_id' => $claim->enterprise_wiki_page_id,
                    'status' => 'ambiguous',
                    'matched_block_keys' => array_map(static fn (array $b): string => (string) ($b['block_key'] ?? ''), $matches),
                ];

                continue;
            }

            $block = $matches[0];
            $blockKey = (string) ($block['block_key'] ?? '');
            $blockKeyChanged = $claim->content_block_key !== $blockKey;

            $existingKeys = EnterpriseWikiSourceReference::query()
                ->where('enterprise_wiki_claim_id', $claim->id)
                ->pluck('source_element_key')
                ->all();

            $referencesToCreate = [];

            foreach ((array) ($block['source_elements'] ?? []) as $element) {
                if (! is_array($element)) {
                    continue;
                }

                $key = (string) ($element['source_element_key'] ?? '');
                $sourceId = (int) ($element['source_id'] ?? 0);

                if ($key === '' || $sourceId <= 0 || in_array($key, $existingKeys, true)) {
                    continue;
                }

                $referencesToCreate[] = [
                    'enterprise_wiki_claim_id' => $claim->id,
                    'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                    'source_id' => $sourceId,
                    'source_element_key' => $key,
                    'source_element_type' => $element['source_element_type'] ?? null,
                    'source_row_key' => $element['source_row_key'] ?? null,
                    'source_label' => (string) ($element['source_label'] ?? 'Kildedokument'),
                    'excerpt' => (string) ($element['source_excerpt'] ?? $anchor),
                    'source_hash' => (string) ($element['source_hash'] ?? ''),
                    'page_reference' => $element['page_reference'] ?? null,
                ];
            }

            if (! $blockKeyChanged && $referencesToCreate === []) {
                $counts['unchanged']++;
                $results[] = [
                    'claim_id' => $claimId,
                    'page_id' => $claim->enterprise_wiki_page_id,
                    'status' => 'unchanged',
                    'block_key' => $blockKey,
                ];

                continue;
            }

            if ($apply) {
                DB::transaction(function () use ($claim, $blockKey, $blockKeyChanged, $referencesToCreate): void {
                    if ($blockKeyChanged) {
                        $claim->update(['content_block_key' => $blockKey]);
                    }

                    foreach ($referencesToCreate as $payload) {
                        EnterpriseWikiSourceReference::query()->firstOrCreate(
                            [
                                'enterprise_wiki_claim_id' => $payload['enterprise_wiki_claim_id'],
                                'source_element_key' => $payload['source_element_key'],
                            ],
                            $payload,
                        );
                    }
                });
            }

            $counts['relinked']++;
            $counts['references_created'] += count($referencesToCreate);
            $results[] = [
                'claim_id' => $claimId,
                'page_id' => $claim->enterprise_wiki_page_id,
                'status' => 'relinked',
                'block_key' => $blockKey,
                'block_key_changed' => $blockKeyChanged,
                'new_source_element_keys' => array_column($referencesToCreate, 'source_element_key'),
            ];
        }

        return array_merge(['results' => $results], $counts);
    }

    /**
     * One-off, explicitly-scoped patch: adds a single missing source_elements entry to an
     * existing block, when that block's own prose demonstrably restates a specific known
     * document paragraph that was never linked as one of its declared source elements.
     *
     * Deliberately NOT a generic "auto-detect every possibly-missing element" sweep — the
     * paragraph-43/block-0001 case that motivated this (claim 3925: block-0001's closing
     * sentence "...mer omfattende utviklingsarbeid gjennomføres etter nærmere avtale som egne
     * rådgivningsaktiviteter" restates paragraph-43's content, but paragraph-43 was never added
     * to the block's source_elements list) is a paraphrase, not a verbatim substring match, so it
     * cannot be safely auto-discovered the way the claim-to-block matching above can. Each call
     * site names the exact page version, block, and paragraph explicitly and must justify why
     * that specific paragraph genuinely backs that specific block's text.
     *
     * Idempotent: does nothing (and reports unchanged=true) if the block already lists the given
     * source_element_key.
     *
     * @return array{applied: bool, changed: bool, reason: string}
     */
    public function addMissingSourceElement(
        EnterpriseWikiPageVersion $version,
        string $blockKey,
        string $sourceElementKey,
        bool $apply,
    ): array {
        $reference = EnterpriseWikiSourceReference::query()
            ->where('source_element_key', $sourceElementKey)
            ->whereNotNull('source_id')
            ->first();

        if ($reference === null) {
            return ['applied' => false, 'changed' => false, 'reason' => "No known source reference for [{$sourceElementKey}]."];
        }

        $blocks = (array) ($version->content_blocks_json ?? []);
        $blockFound = false;

        foreach ($blocks as $index => $block) {
            if (! is_array($block) || (string) ($block['block_key'] ?? '') !== $blockKey) {
                continue;
            }

            $blockFound = true;
            $existingKeys = array_column((array) ($block['source_elements'] ?? []), 'source_element_key');

            if (in_array($sourceElementKey, $existingKeys, true)) {
                return ['applied' => false, 'changed' => false, 'reason' => "Block [{$blockKey}] already lists [{$sourceElementKey}]."];
            }

            $blocks[$index]['source_elements'][] = [
                'source_type' => $reference->source_type,
                'source_id' => $reference->source_id,
                'source_label' => $reference->source_label,
                'source_hash' => $reference->source_hash,
                'document_version_hash' => $reference->source_hash,
                'source_element_key' => $reference->source_element_key,
                'source_element_type' => $reference->source_element_type,
                'source_row_key' => $reference->source_row_key,
                'source_excerpt' => $reference->excerpt,
                'page_reference' => $reference->page_reference,
            ];

            break;
        }

        if (! $blockFound) {
            return ['applied' => false, 'changed' => false, 'reason' => "Block [{$blockKey}] not found in version [{$version->id}]."];
        }

        if ($apply) {
            $version->update(['content_blocks_json' => $blocks]);
        }

        return ['applied' => $apply, 'changed' => true, 'reason' => "Would add [{$sourceElementKey}] to block [{$blockKey}]."];
    }
}
