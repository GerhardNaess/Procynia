<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPageVersion;
use Illuminate\Database\Eloquent\Builder;

class EnterpriseWikiClaimIntegrityRepairService
{
    /**
     * @return array{checked: int, source_based: int, best_practice: int, internal_error: int, unchanged: int, applied: bool}
     */
    public function repair(?int $customerId = null, bool $apply = false): array
    {
        $counts = [
            'checked' => 0,
            'source_based' => 0,
            'best_practice' => 0,
            'internal_error' => 0,
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
                        EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR => 'internal_error',
                        default => 'unchanged',
                    };

                    $counts[$bucket]++;

                    if (! $apply || $classification['content_origin'] === $claim->content_origin) {
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
            || ! $version->is_current
            || ! $this->hasCurrentPageAnchor($claim, $version)) {
            return [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
                'review_reason' => null,
                'generation_issue' => 'claim_not_traceable_to_current_page_version',
            ];
        }

        if ($claim->sourceReferences->isNotEmpty()) {
            return [
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'review_reason' => null,
                'generation_issue' => null,
            ];
        }

        return [
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'review_reason' => 'Innholdet finnes i Wiki-teksten, men har ikke dokumentert kildegrunnlag. Vurder om det skal beholdes som beste praksis.',
            'generation_issue' => null,
        ];
    }

    private function hasCurrentPageAnchor(EnterpriseWikiClaim $claim, EnterpriseWikiPageVersion $version): bool
    {
        $anchor = trim((string) ($claim->page_excerpt ?: $claim->claim_text));

        return $anchor !== '' && $this->containsNormalized((string) ($version->content_markdown ?? ''), $anchor);
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
