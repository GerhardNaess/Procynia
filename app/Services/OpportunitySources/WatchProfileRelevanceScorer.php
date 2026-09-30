<?php

namespace App\Services\OpportunitySources;

use App\Models\WatchProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * How well a hit answers what a watch profile is watching for.
 *
 * This lived inside DoffinWatchProfileInboxDiscoveryService, and nothing in it was ever Doffin's:
 * it reads a WatchProfile's keywords and CPV rules on one side and a NormalizedNotice on the
 * other, neither of which names a register. It stayed there because there was only one worker to
 * keep it in. With a second worker it would have had to be copied, and a scoring rule that exists
 * twice is a scoring rule that will eventually disagree with itself.
 *
 * The weights are unchanged, deliberately and to the point: a keyword in the title or description
 * is worth 20, the same keyword in the buyer's name is worth 8, a matching CPV code is worth its
 * own configured weight, and a hit that matches both kinds gets 10 more. Those numbers are already
 * written into records people look at every day, so this phase moves the code and leaves the
 * arithmetic exactly where it was.
 *
 * TWO KINDS OF KEYWORD LIST, AND WHY BOTH SURVIVED THE MOVE.
 *
 * A watch profile's keywords are stored as JSON, but older rows hold a plain string. The Doffin
 * worker read that string two different ways: for the search it passed the raw text through
 * OpportunitySearchCriteria, which splits on commas and semicolons as well as newlines, and for
 * scoring it treated the same text as one single term. So "renhold, tingrett" was searched as two
 * words and scored as one phrase.
 *
 * That difference is almost certainly accidental. It is also visible in every relevance_score in
 * the database, so quietly unifying the two here would silently re-rank existing watch inboxes
 * under the banner of an extraction. Both readings are kept, named for what they are, and the
 * choice of which to unify is left to whoever decides to change scoring on purpose.
 */
class WatchProfileRelevanceScorer
{
    private const KEYWORD_IN_TEXT_POINTS = 20;

    private const KEYWORD_IN_BUYER_POINTS = 8;

    private const BOTH_KINDS_MATCHED_BONUS = 10;

    public function score(WatchProfile $watchProfile, NormalizedNotice $notice): int
    {
        $keywordMatches = 0;
        $cpvMatches = 0;
        $score = 0;
        $titleAndDescription = Str::lower(Str::squish(
            trim((string) ($notice->title ?? '')).' '.trim((string) ($notice->description ?? ''))
        ));
        $buyerHaystack = Str::lower($notice->buyerName ?? '');
        $hitCpvCodes = $this->noticeCpvCodes($notice);

        foreach ($this->scoringKeywords($watchProfile) as $keyword) {
            $normalizedKeyword = Str::lower($keyword);

            if ($normalizedKeyword === '') {
                continue;
            }

            if (str_contains($titleAndDescription, $normalizedKeyword)) {
                $keywordMatches++;
                $score += self::KEYWORD_IN_TEXT_POINTS;

                continue;
            }

            if ($buyerHaystack !== '' && str_contains($buyerHaystack, $normalizedKeyword)) {
                $keywordMatches++;
                $score += self::KEYWORD_IN_BUYER_POINTS;
            }
        }

        foreach ($watchProfile->cpvCodes as $cpvRule) {
            $cpvCode = preg_replace('/\D+/', '', (string) $cpvRule->cpv_code) ?? '';

            if ($cpvCode === '' || ! $hitCpvCodes->contains($cpvCode)) {
                continue;
            }

            $cpvMatches++;
            $score += max(1, (int) $cpvRule->weight);
        }

        if ($keywordMatches > 0 && $cpvMatches > 0) {
            $score += self::BOTH_KINDS_MATCHED_BONUS;
        }

        return $score;
    }

    /**
     * The terms a hit is scored against.
     *
     * @return list<string>
     */
    public function scoringKeywords(WatchProfile $watchProfile): array
    {
        $rawKeywords = $watchProfile->getRawOriginal('keywords');

        if (is_string($rawKeywords)) {
            $trimmed = trim($rawKeywords);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return $this->meaningfulStringValues($decoded);
            }

            // One term, not a list. See the class note: this is how scoring has always read a
            // plain-string profile, and changing it would re-rank existing records.
            return [$trimmed];
        }

        if (is_array($watchProfile->keywords)) {
            return $this->meaningfulStringValues($watchProfile->keywords);
        }

        return [];
    }

    /**
     * The terms a register is asked about, normalised the way every other search is.
     *
     * @return list<string>
     */
    public function searchKeywords(WatchProfile $watchProfile): array
    {
        return OpportunitySearchCriteria::fromArray([
            'keywords' => $this->keywordsFilterText($watchProfile),
        ])->keywords;
    }

    /** @return list<string> */
    public function searchCpvCodes(WatchProfile $watchProfile): array
    {
        return OpportunitySearchCriteria::fromArray([
            'cpv_codes' => $watchProfile->cpvCodes->pluck('cpv_code')->all(),
        ])->cpvCodes;
    }

    /**
     * Whether this profile says anything a register can be narrowed by.
     *
     * A profile with neither keywords nor CPV codes describes no search. Against Doffin that has
     * always meant "everything published in Norway yesterday", scored to zero and stored nowhere —
     * wasteful but harmless. Against a register the size of the EU it is not harmless, which is
     * why the question is asked here rather than assumed.
     */
    public function hasSearchableCriteria(WatchProfile $watchProfile): bool
    {
        return $this->searchKeywords($watchProfile) !== [] || $this->searchCpvCodes($watchProfile) !== [];
    }

    private function keywordsFilterText(WatchProfile $watchProfile): string
    {
        $rawKeywords = $watchProfile->getRawOriginal('keywords');

        if (is_string($rawKeywords)) {
            $trimmed = trim($rawKeywords);

            if ($trimmed === '') {
                return '';
            }

            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return implode("\n", $this->meaningfulStringValues($decoded));
            }

            return $trimmed;
        }

        if (is_array($watchProfile->keywords)) {
            return implode("\n", $this->meaningfulStringValues($watchProfile->keywords));
        }

        return '';
    }

    /** @return Collection<int, string> */
    private function noticeCpvCodes(NormalizedNotice $notice): Collection
    {
        return collect($notice->cpvCodes)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (string|int|float|bool $value): string => preg_replace('/\D+/', '', (string) $value) ?? '')
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values();
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<string>
     */
    private function meaningfulStringValues(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (string|int|float|bool $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
