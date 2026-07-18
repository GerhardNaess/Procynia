<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Database\Eloquent\Builder;

class EnterpriseWikiClaimIntegrityRepairService
{
    /**
     * @return array{checked: int, source_based: int, best_practice: int, unsupported_generated_content: int, internal_error: int, wrong_version: int, missing_anchor: int, unchanged: int, applied: bool}
     */
    public function repair(?int $customerId = null, bool $apply = false): array
    {
        $counts = [
            'checked' => 0,
            'source_based' => 0,
            'best_practice' => 0,
            'unsupported_generated_content' => 0,
            'internal_error' => 0,
            'wrong_version' => 0,
            'missing_anchor' => 0,
            'unchanged' => 0,
            'applied' => $apply,
        ];

        $this->query($customerId)
            ->chunkById(100, function ($claims) use (&$counts, $apply): void {
                foreach ($claims as $claim) {
                    $counts['checked']++;

                    $classification = $this->classify($claim);
                    $bucket = match ($classification['content_origin']) {
                        EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED => 'source_based',
                        EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE => 'best_practice',
                        EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT => 'unsupported_generated_content',
                        EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR => 'internal_error',
                        default => 'unchanged',
                    };

                    $counts[$bucket]++;
                    $issue = $classification['generation_issue'] ?? null;
                    if ($issue === 'claim_not_tied_to_current_page_version') {
                        $counts['wrong_version']++;
                    }
                    if ($issue === 'claim_missing_unique_content_block_anchor') {
                        $counts['missing_anchor']++;
                    }

                    $legacyBlocks = $classification['legacy_content_blocks'] ?? null;
                    unset($classification['legacy_content_blocks']);

                    if ($apply
                        && is_array($legacyBlocks)
                        && $claim->version instanceof EnterpriseWikiPageVersion
                        && empty($claim->version->content_blocks_json)) {
                        $claim->version->update(['content_blocks_json' => $legacyBlocks]);
                    }

                    if (! $apply || ! $this->claimNeedsUpdate($claim, $classification)) {
                        continue;
                    }

                    $claim->update($classification);
                }
            });

        return $counts;
    }

    private function query(?int $customerId): Builder
    {
        return EnterpriseWikiClaim::query()
            ->with(['version', 'sourceReferences', 'page'])
            ->when($customerId !== null, fn (Builder $query): Builder => $query->whereHas(
                'page',
                fn (Builder $pageQuery): Builder => $pageQuery->where('customer_id', $customerId),
            ))
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function classify(EnterpriseWikiClaim $claim): array
    {
        $version = $claim->version;

        if (! $version instanceof EnterpriseWikiPageVersion
            || (int) $version->enterprise_wiki_page_id !== (int) $claim->enterprise_wiki_page_id
            || ! $version->is_current) {
            return [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => null,
                'review_metadata' => null,
                'generation_issue' => 'claim_not_tied_to_current_page_version',
            ];
        }

        $blocks = (array) ($version->content_blocks_json ?? []);
        $legacyBlocks = [];

        if ($blocks === []) {
            $legacyBlocks = $this->buildLegacyBlocks($version);
            $blocks = $legacyBlocks;
        }

        $block = $this->findUniqueBlockForClaim($claim, $blocks);

        if ($block === null) {
            return [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => null,
                'review_metadata' => null,
                'generation_issue' => 'claim_missing_unique_content_block_anchor',
            ];
        }

        if ($claim->sourceReferences->isNotEmpty()) {
            return [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'content_block_key' => $block['block_key'] ?? $claim->content_block_key,
                'review_reason' => null,
                'review_metadata' => null,
                'generation_issue' => null,
                'legacy_content_blocks' => $legacyBlocks,
            ];
        }

        if ($this->isPositiveBestPracticeSuggestion($claim)) {
            return [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
                'content_block_key' => $block['block_key'] ?? $claim->content_block_key,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => 'Innholdet er formulert som en anbefaling eller etablert praksis uten direkte kildegrunnlag. Vurder om det skal beholdes som beste praksis.',
                'review_metadata' => [
                    'statement_kind' => 'recommendation',
                    'classification_basis' => 'normative_language',
                    'suggested_placement' => $block['block_key'] ?? null,
                    'visible_wiki_link_recommendation' => 'auto_evaluate',
                ],
                'generation_issue' => null,
                'legacy_content_blocks' => $legacyBlocks,
            ];
        }

        return [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'content_block_key' => $block['block_key'] ?? $claim->content_block_key,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'review_reason' => null,
            'review_metadata' => null,
            'generation_issue' => 'unsupported_generated_content',
            'legacy_content_blocks' => $legacyBlocks,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>|null
     */
    private function findUniqueBlockForClaim(EnterpriseWikiClaim $claim, array $blocks): ?array
    {
        $anchor = trim((string) ($claim->page_excerpt ?: $claim->claim_text));
        $matches = [];

        if ($anchor === '') {
            return null;
        }

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($claim->content_block_key ?? null) !== null && ($block['block_key'] ?? null) === $claim->content_block_key) {
                return $this->containsNormalized((string) ($block['markdown'] ?? ''), $anchor) ? $block : null;
            }

            if ($this->containsNormalized((string) ($block['markdown'] ?? ''), $anchor)) {
                $matches[] = $block;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLegacyBlocks(EnterpriseWikiPageVersion $version): array
    {
        $parts = preg_split("/\n{2,}/", trim((string) $version->content_markdown)) ?: [];
        $blocks = [];

        foreach ($parts as $part) {
            $markdown = trim((string) $part);

            if ($markdown === '') {
                continue;
            }

            $blocks[] = [
                'block_key' => 'block-'.str_pad((string) (count($blocks) + 1), 4, '0', STR_PAD_LEFT),
                'position' => count($blocks),
                'markdown' => $markdown,
                'content_origin' => null,
                'source_type' => null,
                'source_id' => null,
                'source_label' => null,
                'source_hash' => null,
                'document_version_hash' => null,
                'source_element_key' => null,
                'source_element_type' => null,
                'source_row_key' => null,
                'source_excerpt' => null,
                'page_reference' => null,
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $classification
     */
    private function claimNeedsUpdate(EnterpriseWikiClaim $claim, array $classification): bool
    {
        foreach ($classification as $key => $value) {
            $current = $claim->{$key};

            if (is_array($current) || is_array($value)) {
                if ($current != $value) {
                    return true;
                }

                continue;
            }

            if ($current !== $value) {
                return true;
            }
        }

        return false;
    }

    private function isPositiveBestPracticeSuggestion(EnterpriseWikiClaim $claim): bool
    {
        if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            return true;
        }

        $metadata = (array) ($claim->review_metadata ?? []);

        return in_array(($metadata['classification_basis'] ?? null), [
            'ai_block_content_origin',
            'approved_best_practice',
        ], true);
    }

    private function containsNormalized(string $haystack, string $needle): bool
    {
        $normalize = static fn (string $value): string => preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $needle = $normalize($needle);

        return $needle !== '' && str_contains(
            mb_strtolower($normalize($haystack)),
            mb_strtolower($needle),
        );
    }
}
