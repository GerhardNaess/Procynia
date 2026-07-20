<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use Illuminate\Support\Facades\DB;

/**
 * General repair for a claim whose content_block_key is missing/stale relative to the CURRENT
 * page version, or whose resolved block's declared source elements don't actually cover what the
 * claim asserts — without regenerating any page content and without calling AI.
 *
 * Nothing in this class is specific to any one run, document, page, or paragraph — every input
 * (run, claim IDs, blocks, source elements) is read from the database at call time. Callers pass
 * an explicit list of claim IDs (never "every unsupported claim in the run") because deciding
 * WHICH claims need repair is an investigation step outside this service's responsibility; this
 * service only decides, for a given claim, whether a safe repair exists.
 *
 * Two independent matching steps, each with its own "don't guess" rule:
 *
 * 1. Block matching (mirrors EnterpriseWikiClaimIntegrityRepairService::findUniqueBlockForClaim(),
 *    but reuses the more tolerant EnterpriseWikiClaimAnchorTextNormalizer instead of a bare
 *    substring check, since that normalizer already resolves wikilink/whitespace/punctuation
 *    artifacts a captured anchor can contain). A claim's anchor (page_excerpt, falling back to
 *    claim_text) must be a substring of EXACTLY ONE block's markdown in the claim's current page
 *    version. Zero or more than one match is reported as unresolved and left untouched.
 *
 * 2. Source-element discovery, once a block is uniquely resolved: the block's OWN declared
 *    source_elements are always restored first. If the claim's anchor still isn't well covered by
 *    those (e.g. the block is a synthesis/summary with no declared elements at all, or covers only
 *    part of what the claim asserts), each clause of the anchor is scored against every other
 *    known source element for the same document via significant-token containment
 *    (|shared tokens| / min(|clause tokens|, |candidate tokens|)). A clause only contributes a new
 *    candidate when its best match clears both a minimum absolute overlap and a minimum
 *    containment score, AND is clearly ahead of the next-best candidate — a close second means the
 *    clause is genuinely ambiguous between two source paragraphs and contributes nothing rather
 *    than guessing.
 *
 * Idempotent: re-running with the same claim IDs never creates duplicate source references (keyed
 * on claim_id + source_element_key) and never re-reports a claim whose content_block_key already
 * matches its unique block with all discoverable source elements already present.
 */
class EnterpriseWikiRepairRunClaimSourceLinksService
{
    /** A clause must share at least this many significant tokens with a candidate to count at all. */
    private const MIN_OVERLAP_TOKENS = 5;

    /** Containment score (shared / smaller side) a clause's best candidate must reach. */
    private const MIN_CONTAINMENT_SCORE = 0.55;

    /** The best candidate must lead the runner-up by at least this much, or the clause is ambiguous. */
    private const AMBIGUITY_MARGIN = 0.15;

    /** Clauses shorter than this many significant tokens are too generic to score reliably. */
    private const MIN_CLAUSE_TOKENS = 3;

    public function __construct(
        private readonly EnterpriseWikiClaimAnchorTextNormalizer $textNormalizer,
        private readonly EnterpriseWikiClaimCanonicalizationService $canonicalizationService,
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

        $catalog = null; // lazily loaded — only needed once a claim actually reaches step 2
        $versions = []; // version_id => EnterpriseWikiPageVersion, fetched once per version

        $results = [];
        $counts = ['relinked' => 0, 'references_created' => 0, 'ambiguous' => 0, 'no_match' => 0, 'unchanged' => 0, 'not_found' => 0];

        // Phase 1: resolve each claim's unique block against an ORIGINAL, unmodified snapshot of
        // its page version's blocks, and discover any new source elements its own clauses find.
        // Deliberately not persisted yet — two different claims anchored to the SAME block (a
        // block routinely yields more than one claim) must each see the OTHER's discoveries too,
        // not just their own, or the repair only converges after being run twice. Grouping by
        // block below merges everyone's discoveries before anything is written.
        $resolved = [];
        $discoveredByBlock = []; // "versionId:blockIndex" => [source_element_key => candidate]

        foreach (array_unique($claimIds) as $claimId) {
            $claim = EnterpriseWikiClaim::query()->find($claimId);

            if ($claim === null || ! $pageIds->contains($claim->enterprise_wiki_page_id)) {
                $counts['not_found']++;
                $results[] = ['claim_id' => $claimId, 'status' => 'not_found_in_run'];

                continue;
            }

            $version = $versions[$claim->enterprise_wiki_page_id] ??= EnterpriseWikiPageVersion::query()
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

            foreach ($blocks as $index => $block) {
                if (is_array($block) && $anchor !== '' && $this->textNormalizer->contains((string) ($block['markdown'] ?? ''), $anchor)) {
                    $matches[] = $index;
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
                    'matched_block_keys' => array_map(
                        static fn (int $i): string => (string) ($blocks[$i]['block_key'] ?? ''),
                        $matches,
                    ),
                ];

                continue;
            }

            $blockIndex = $matches[0];
            $block = $blocks[$blockIndex];
            $blockKey = (string) ($block['block_key'] ?? '');
            $blockGroupKey = $version->id.':'.$blockIndex;

            $existingKeys = EnterpriseWikiSourceReference::query()
                ->where('enterprise_wiki_claim_id', $claim->id)
                ->pluck('source_element_key')
                ->all();

            $declaredKeys = array_column((array) ($block['source_elements'] ?? []), 'source_element_key');
            $ambiguousClauses = 0;

            if ($this->needsSupplementalDiscovery($anchor, $block)) {
                $catalog ??= $this->loadDocumentCatalog($run);
                $discovery = $this->discoverStrongCandidates($anchor, $catalog, array_merge($existingKeys, $declaredKeys));
                $ambiguousClauses = $discovery['ambiguous_clauses'];

                foreach ($discovery['candidates'] as $candidate) {
                    $discoveredByBlock[$blockGroupKey][(string) $candidate['source_element_key']] ??= $candidate;
                }
            }

            $resolved[] = [
                'claim' => $claim,
                'version' => $version,
                'block_index' => $blockIndex,
                'block_key' => $blockKey,
                'block_group_key' => $blockGroupKey,
                'existing_keys' => $existingKeys,
                'ambiguous_clauses' => $ambiguousClauses,
                'anchor' => $anchor,
            ];
        }

        // Phase 2: every claim anchored to a given block now sees that block's FULL, merged set
        // of newly-discovered elements (not just whatever its own clauses individually found) —
        // this is what makes a single call converge instead of requiring a second run.
        foreach ($resolved as $entry) {
            $claim = $entry['claim'];
            $version = $entry['version'];
            $blockIndex = $entry['block_index'];
            $blocks = (array) ($version->content_blocks_json ?? []);
            $block = $blocks[$blockIndex];
            $blockKey = $entry['block_key'];
            $blockKeyChanged = $claim->content_block_key !== $blockKey;

            $referencesToCreate = $this->declaredElementPayloads($block, $claim->id, $entry['anchor'], $entry['existing_keys']);
            $newKeysSoFar = array_merge($entry['existing_keys'], array_column($referencesToCreate, 'source_element_key'));

            $newBlockSourceElements = [];

            foreach ($discoveredByBlock[$entry['block_group_key']] ?? [] as $key => $candidate) {
                if (in_array($key, $newKeysSoFar, true)) {
                    continue;
                }

                $referencesToCreate[] = [
                    'enterprise_wiki_claim_id' => $claim->id,
                    'source_type' => $candidate['source_type'],
                    'source_id' => $candidate['source_id'],
                    'source_element_key' => $candidate['source_element_key'],
                    'source_element_type' => $candidate['source_element_type'],
                    'source_row_key' => $candidate['source_row_key'],
                    'source_label' => $candidate['source_label'] ?: 'Kildedokument',
                    'excerpt' => $candidate['excerpt'],
                    'source_hash' => $candidate['source_hash'] ?: '',
                    'page_reference' => $candidate['page_reference'],
                ];
                $newBlockSourceElements[] = $candidate;
            }

            if (! $blockKeyChanged && $referencesToCreate === []) {
                $counts['unchanged']++;
                $results[] = [
                    'claim_id' => $claim->id,
                    'page_id' => $claim->enterprise_wiki_page_id,
                    'status' => 'unchanged',
                    'block_key' => $blockKey,
                    'ambiguous_clauses' => $entry['ambiguous_clauses'],
                ];

                continue;
            }

            if ($apply) {
                DB::transaction(function () use ($claim, $version, $blockIndex, $blockKey, $blockKeyChanged, $referencesToCreate, $newBlockSourceElements): void {
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

                    if ($newBlockSourceElements !== []) {
                        $this->appendBlockSourceElements($version, $blockIndex, $newBlockSourceElements);
                    }
                });
            }

            $counts['relinked']++;
            $counts['references_created'] += count($referencesToCreate);
            $results[] = [
                'claim_id' => $claim->id,
                'page_id' => $claim->enterprise_wiki_page_id,
                'status' => 'relinked',
                'block_key' => $blockKey,
                'block_key_changed' => $blockKeyChanged,
                'new_source_element_keys' => array_column($referencesToCreate, 'source_element_key'),
                'ambiguous_clauses' => $entry['ambiguous_clauses'],
            ];
        }

        return array_merge(['results' => $results], $counts);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $existingKeys
     * @return list<array<string, mixed>>
     */
    private function declaredElementPayloads(array $block, int $claimId, string $anchor, array $existingKeys): array
    {
        $payloads = [];

        foreach ((array) ($block['source_elements'] ?? []) as $element) {
            if (! is_array($element)) {
                continue;
            }

            $key = (string) ($element['source_element_key'] ?? '');
            $sourceId = (int) ($element['source_id'] ?? 0);

            if ($key === '' || $sourceId <= 0 || in_array($key, $existingKeys, true)) {
                continue;
            }

            $payloads[] = [
                'enterprise_wiki_claim_id' => $claimId,
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

        return $payloads;
    }

    /**
     * Step 2 only runs when the block's own declared elements don't already give the claim's
     * anchor solid coverage — a block with candidates that already cover the anchor well has no
     * "insufficient candidates" problem for this step to solve.
     *
     * @param  array<string, mixed>  $block
     */
    private function needsSupplementalDiscovery(string $anchor, array $block): bool
    {
        $declaredCount = count((array) ($block['source_elements'] ?? []));

        if ($declaredCount === 0) {
            return true;
        }

        $anchorTokens = $this->canonicalizationService->significantTokens($this->textNormalizer->normalize($anchor));

        if ($anchorTokens === []) {
            return false;
        }

        $excerpts = array_column((array) ($block['source_elements'] ?? []), 'source_excerpt');
        $combined = implode(' ', array_filter(array_map('strval', $excerpts)));
        $combinedTokens = $this->canonicalizationService->significantTokens($this->textNormalizer->normalize($combined));

        $covered = count(array_intersect($anchorTokens, $combinedTokens));

        return ($covered / count($anchorTokens)) < self::MIN_CONTAINMENT_SCORE;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDocumentCatalog(EnterpriseWikiIngestRun $run): array
    {
        return EnterpriseWikiSourceReference::query()
            ->where('source_type', $run->source_type)
            ->where('source_id', $run->source_id)
            ->get([
                'source_type', 'source_id', 'source_element_key', 'source_element_type',
                'source_row_key', 'source_label', 'excerpt', 'source_hash', 'page_reference',
            ])
            ->unique('source_element_key')
            ->filter(fn ($row): bool => (string) $row->source_element_key !== '' && trim((string) $row->excerpt) !== '')
            ->values()
            ->map(fn ($row): array => $row->toArray())
            ->all();
    }

    /**
     * Splits the anchor into clauses (the same boundaries filterToRelevantSentences() uses) and
     * scores each clause independently against the document's full known source-element catalog —
     * a claim combining two distinct facts should be able to find one candidate per fact, not be
     * forced through a single whole-anchor comparison that dilutes both.
     *
     * @param  list<array<string, mixed>>  $catalog
     * @param  list<string>  $existingKeys
     * @return array{candidates: list<array<string, mixed>>, ambiguous_clauses: int}
     */
    private function discoverStrongCandidates(string $anchor, array $catalog, array $existingKeys): array
    {
        $clauses = $this->canonicalizationService->splitIntoClauses($anchor);
        $candidates = [];
        $foundKeys = $existingKeys;
        $ambiguousClauses = 0;

        foreach ($clauses as $clause) {
            $clauseTokens = $this->canonicalizationService->significantTokens($this->textNormalizer->normalize($clause));

            if (count($clauseTokens) < self::MIN_CLAUSE_TOKENS) {
                continue;
            }

            $scored = [];

            foreach ($catalog as $candidate) {
                $key = (string) $candidate['source_element_key'];

                if (in_array($key, $foundKeys, true)) {
                    continue;
                }

                $candidateTokens = $this->canonicalizationService->significantTokens($this->textNormalizer->normalize((string) $candidate['excerpt']));
                $overlap = count(array_intersect($clauseTokens, $candidateTokens));
                $denominator = min(count($clauseTokens), count($candidateTokens));

                if ($denominator === 0 || $overlap < self::MIN_OVERLAP_TOKENS) {
                    continue;
                }

                $scored[$key] = ['score' => $overlap / $denominator, 'candidate' => $candidate];
            }

            if ($scored === []) {
                continue;
            }

            uasort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $ranked = array_values($scored);

            if ($ranked[0]['score'] < self::MIN_CONTAINMENT_SCORE) {
                continue;
            }

            $runnerUpScore = $ranked[1]['score'] ?? 0.0;

            if ($ranked[0]['score'] - $runnerUpScore < self::AMBIGUITY_MARGIN) {
                $ambiguousClauses++;

                continue;
            }

            $winner = $ranked[0]['candidate'];
            $candidates[] = $winner;
            $foundKeys[] = (string) $winner['source_element_key'];
        }

        return ['candidates' => $candidates, 'ambiguous_clauses' => $ambiguousClauses];
    }

    /**
     * @param  list<array<string, mixed>>  $newElements
     */
    private function appendBlockSourceElements(EnterpriseWikiPageVersion $version, int $blockIndex, array $newElements): void
    {
        $blocks = (array) ($version->content_blocks_json ?? []);

        if (! isset($blocks[$blockIndex]) || ! is_array($blocks[$blockIndex])) {
            return;
        }

        $existingKeys = array_column((array) ($blocks[$blockIndex]['source_elements'] ?? []), 'source_element_key');

        foreach ($newElements as $element) {
            $key = (string) $element['source_element_key'];

            if (in_array($key, $existingKeys, true)) {
                continue;
            }

            $blocks[$blockIndex]['source_elements'][] = [
                'source_type' => $element['source_type'],
                'source_id' => $element['source_id'],
                'source_label' => $element['source_label'],
                'source_hash' => $element['source_hash'],
                'document_version_hash' => $element['source_hash'],
                'source_element_key' => $key,
                'source_element_type' => $element['source_element_type'],
                'source_row_key' => $element['source_row_key'],
                'source_excerpt' => $element['excerpt'],
                'page_reference' => $element['page_reference'],
            ];
            $existingKeys[] = $key;
        }

        $version->update(['content_blocks_json' => $blocks]);
    }
}
