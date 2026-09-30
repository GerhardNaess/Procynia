<?php

namespace App\Services\Ted;

use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunitySearchCriteria;
use App\Services\OpportunitySources\OpportunitySourceAdapter;
use App\Services\OpportunitySources\OpportunitySourceSearchResult;
use App\Services\OpportunitySources\OpportunityStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * TED — Tenders Electronic Daily, the EU's own register — as a Procynia opportunity source.
 *
 * The second adapter, and therefore the first real test of whether the four preceding phases
 * actually bought anything. They did: nothing above this class changed to accommodate it. The
 * registry addresses it by key, the controller asks it the same OpportunitySearchCriteria it asks
 * Doffin, and the rest of Procynia sees NormalizedNotice exactly as before.
 *
 * TWO THINGS TED DOES DIFFERENTLY, AND WHAT THIS CLASS DOES ABOUT THEM.
 *
 * It has no status field. Doffin says ACTIVE or AWARDED; TED's API rejects `notice-status`
 * outright. What it has is `notice-type`, which is the kind of document — a contract notice is a
 * live call for offers, an award notice records that somebody won. Those two genuinely determine
 * the lifecycle, so they are mapped; a prior information notice is an announcement of something
 * not yet open, which is none of the four states, and anything unrecognised is null. Never Open by
 * default: putting a closed procurement in front of a bid manager as a live one is the failure
 * worth designing against.
 *
 * Everything it returns is multilingual. `notice-title` is {"pol": "..."}, `buyer-name` is
 * {"pol": ["..."]} — a notice from Warsaw carries Polish, one from Lisbon carries Portuguese, and
 * both carry an English translation more often than not. Procynia has one title field, so this
 * picks English first and falls back to whatever the notice actually has rather than showing
 * nothing.
 */
class TedSourceAdapter implements OpportunitySourceAdapter
{
    public const SOURCE_KEY = 'ted';

    /** TED's language codes are three letters. English first, then Norwegian, then anything. */
    private const PREFERRED_LANGUAGES = ['ENG', 'eng', 'NOR', 'nor', 'nob', 'NOB'];

    /**
     * Document kinds that settle the question of whether an opportunity is live.
     *
     * Prefixes, because TED subdivides each kind: cn-standard, cn-social, can-standard,
     * can-social, can-tran, and so on.
     */
    private const OPEN_NOTICE_PREFIX = 'cn-';

    private const AWARDED_NOTICE_PREFIX = 'can-';

    public function __construct(
        private readonly TedSearchClient $client,
    ) {}

    public function sourceKey(): string
    {
        return self::SOURCE_KEY;
    }

    public function label(): string
    {
        return 'Live søk i TED';
    }

    public function search(OpportunitySearchCriteria $criteria, int $page, int $perPage): OpportunitySourceSearchResult
    {
        $response = $this->client->search(
            $this->toExpertQuery($criteria),
            (array) config('ted.search_fields', []),
            $page,
            $perPage,
        );

        $page = max(1, (int) ($response['page'] ?? $page));
        $perPage = max(1, (int) ($response['perPage'] ?? $perPage));

        if (! ($response['ok'] ?? true)) {
            $errorType = (string) ($response['error_type'] ?? 'unexpected_response');

            return new OpportunitySourceSearchResult(
                ok: false,
                notices: [],
                page: $page,
                perPage: $perPage,
                numHitsTotal: 0,
                numHitsAccessible: 0,
                fallbackUsed: false,
                errorType: $errorType,
                errorMessage: is_string($response['error_message'] ?? null) ? $response['error_message'] : null,
                userMessage: $this->userMessageForErrorType($errorType),
                upstreamStatus: $response['upstream_status'] ?? null,
            );
        }

        $notices = collect($response['items'] ?? [])
            ->filter(fn (mixed $hit): bool => is_array($hit))
            ->map(fn (array $hit): ?NormalizedNotice => $this->normalizeLiveSearchHit($hit))
            ->filter()
            ->values()
            ->all();
        $total = (int) ($response['numHitsTotal'] ?? count($notices));

        return new OpportunitySourceSearchResult(
            ok: true,
            notices: $notices,
            page: $page,
            perPage: $perPage,
            numHitsTotal: $total,
            numHitsAccessible: (int) ($response['numHitsAccessible'] ?? $total),
            fallbackUsed: (bool) ($response['fallback_used'] ?? false),
        );
    }

    public function normalizeLiveSearchHit(array $hit): ?NormalizedNotice
    {
        $externalId = trim((string) ($hit['publication-number'] ?? ''));

        if ($externalId === '') {
            return null;
        }

        $description = $this->preferredText($hit['description-proc'] ?? null);

        return new NormalizedNotice(
            sourceKey: $this->sourceKey(),
            externalId: $externalId,
            title: $this->preferredText($hit['notice-title'] ?? null),
            description: $description !== null ? Str::squish($description) : null,
            buyerName: $this->preferredText($hit['buyer-name'] ?? null),
            publicationDate: $this->isoDate($hit['publication-date'] ?? null),
            // TED returns this as a list — one deadline per lot. The earliest is the one that
            // actually constrains a bidder, so that is the one Procynia shows.
            deadline: $this->earliestDate($hit['deadline-receipt-request'] ?? null),
            status: $this->statusFromNoticeType($hit['notice-type'] ?? null),
            sourceUrl: $this->resolveSourceUrl($hit, $externalId),
            cpvCodes: $this->cpvCodes($hit['classification-cpv'] ?? null),
            rawPayload: $hit,
        );
    }

    public function sourceUrl(string $externalId): ?string
    {
        if (trim($externalId) === '') {
            return null;
        }

        return sprintf((string) config('ted.public_notice_url'), rawurlencode(trim($externalId)));
    }

    /**
     * The criteria, in TED's expert-search language.
     *
     * This method is the only place that language exists. Every clause below was run against the
     * live API before it was written down.
     *
     * TED requires a non-empty query, so a search with no criteria at all asks for everything
     * published in the last week rather than sending something the API will reject. That is a
     * narrower default than Doffin's, and deliberately so: TED is the whole EU, and "everything,
     * ever" is not a search anybody meant to run.
     */
    private function toExpertQuery(OpportunitySearchCriteria $criteria): string
    {
        $clauses = [];

        if ($criteria->query !== null) {
            $clauses[] = sprintf('FT~%s', $this->quote($criteria->query));
        }

        if ($criteria->hasKeywords()) {
            $joiner = $criteria->matchAllKeywords ? ' AND ' : ' OR ';
            $terms = array_map(fn (string $keyword): string => sprintf('FT~%s', $this->quote($keyword)), $criteria->keywords);

            $clauses[] = count($terms) === 1 ? $terms[0] : '('.implode($joiner, $terms).')';
        }

        if ($criteria->buyerName !== null) {
            $clauses[] = sprintf('buyer-name~%s', $this->quote($criteria->buyerName));
        }

        if ($criteria->cpvCodes !== []) {
            $clauses[] = sprintf('classification-cpv IN (%s)', implode(' ', $criteria->cpvCodes));
        }

        foreach ($this->publicationDateClauses($criteria) as $clause) {
            $clauses[] = $clause;
        }

        if ($criteria->status !== null) {
            $clauses[] = $this->noticeTypeClause($criteria->status);
        }

        // TED rejects an empty query, and "everything TED has ever published" is not what an empty
        // form means. A week is the same order as Doffin's default view.
        return $clauses === []
            ? sprintf('publication-date>=%s', now()->subWeek()->format('Ymd'))
            : implode(' AND ', $clauses);
    }

    /**
     * TED takes dates as YYYYMMDD, and has no notion of a rolling window — so a window is turned
     * into the range it actually means, here, where the conversion can be seen.
     *
     * @return list<string>
     */
    private function publicationDateClauses(OpportunitySearchCriteria $criteria): array
    {
        $from = $this->compactDate($criteria->publishedFrom);
        $to = $this->compactDate($criteria->publishedTo);

        if ($from === null && $to === null && $criteria->publishedWithinDays !== null) {
            $from = now()->subDays($criteria->publishedWithinDays)->format('Ymd');
        }

        return array_values(array_filter([
            $from !== null ? sprintf('publication-date>=%s', $from) : null,
            $to !== null ? sprintf('publication-date<=%s', $to) : null,
        ]));
    }

    /**
     * A lifecycle stage, asked for in the only terms TED offers.
     *
     * Expired and Cancelled have no document kind of their own — TED records the call and the
     * award, not the silence in between — so asking for either excludes both kinds it does know
     * about. That returns nothing rather than returning the wrong thing, which is the right
     * failure: a bid manager filtering for cancelled procurements should see an empty list, not a
     * list of live ones.
     */
    private function noticeTypeClause(OpportunityStatus $status): string
    {
        return match ($status) {
            OpportunityStatus::Open => 'notice-type~"cn-"',
            OpportunityStatus::Awarded => 'notice-type~"can-"',
            OpportunityStatus::Expired, OpportunityStatus::Cancelled => 'notice-type~"no-such-notice-type"',
        };
    }

    /**
     * What kind of document this is, read as what it says about the opportunity.
     *
     * TED has no status field, so this is the closest honest reading. A contract notice is a live
     * call; an award notice means somebody won. A prior information notice announces something not
     * yet open — which is not Open, and not any other case either — and an unrecognised kind is
     * null. Nothing defaults to Open.
     */
    private function statusFromNoticeType(mixed $noticeType): ?OpportunityStatus
    {
        $type = strtolower(trim((string) $noticeType));

        if ($type === '') {
            return null;
        }

        if (str_starts_with($type, self::AWARDED_NOTICE_PREFIX)) {
            return OpportunityStatus::Awarded;
        }

        if (str_starts_with($type, self::OPEN_NOTICE_PREFIX)) {
            return OpportunityStatus::Open;
        }

        return null;
    }

    /**
     * The link TED itself gives, in the language Procynia prefers, or the canonical detail URL.
     *
     * The response carries htmlDirect and html maps keyed by language. Using what the register
     * supplied is better than rebuilding it: if TED moves its detail pages, a stored link keeps
     * working and only new searches change.
     */
    private function resolveSourceUrl(array $hit, string $externalId): ?string
    {
        foreach (['htmlDirect', 'html'] as $key) {
            $byLanguage = $hit['links'][$key] ?? null;

            if (! is_array($byLanguage) || $byLanguage === []) {
                continue;
            }

            foreach (self::PREFERRED_LANGUAGES as $language) {
                if (is_string($byLanguage[$language] ?? null) && trim($byLanguage[$language]) !== '') {
                    return trim($byLanguage[$language]);
                }
            }

            $first = collect($byLanguage)->first(fn (mixed $url): bool => is_string($url) && trim($url) !== '');

            if (is_string($first)) {
                return trim($first);
            }
        }

        return $this->sourceUrl($externalId);
    }

    /**
     * One string out of TED's language map.
     *
     * The values are sometimes a string and sometimes a list of them — buyer-name is a list even
     * when there is one buyer. English first because Procynia's users read it; then Norwegian,
     * which a Norwegian buyer publishing to TED will have; then whatever the notice has, because a
     * Polish title is more use than an empty field.
     */
    private function preferredText(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->stringOrNull($value);
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        foreach (self::PREFERRED_LANGUAGES as $language) {
            $text = $this->flattenText($value[$language] ?? null);

            if ($text !== null) {
                return $text;
            }
        }

        foreach ($value as $candidate) {
            $text = $this->flattenText($candidate);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function flattenText(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)->first(fn (mixed $item): bool => is_string($item) && trim($item) !== '');
        }

        return is_string($value) ? $this->stringOrNull($value) : null;
    }

    /** @return list<string> */
    private function cpvCodes(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(fn (mixed $code): bool => is_scalar($code))
            ->map(fn (string|int|float|bool $code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** TED dates carry an offset — "2026-01-02+01:00". Procynia stores the day. */
    private function isoDate(mixed $value): ?string
    {
        $raw = $this->stringOrNull(is_array($value) ? ($value[0] ?? null) : $value);

        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** One deadline per lot; the earliest is the one that actually constrains a bidder. */
    private function earliestDate(mixed $value): ?string
    {
        $dates = collect(is_array($value) ? $value : [$value])
            ->map(fn (mixed $item): ?string => $this->isoDate($item))
            ->filter()
            ->sort()
            ->values();

        return $dates->first();
    }

    /** Expert-search string literals are double-quoted; an embedded quote would end the clause. */
    private function quote(string $value): string
    {
        return '"'.str_replace('"', ' ', Str::squish($value)).'"';
    }

    private function compactDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Ymd');
        } catch (\Throwable) {
            return null;
        }
    }

    private function userMessageForErrorType(string $errorType): string
    {
        return match ($errorType) {
            'invalid_request' => 'Søket mot TED ble avvist. Kontroller filtrene og prøv igjen.',
            'upstream_unavailable' => 'TED er midlertidig utilgjengelig. Prøv igjen om litt.',
            'timeout' => 'TED svarte ikke i tide. Prøv igjen om litt.',
            'connection_error' => 'Klarte ikke å koble til TED. Prøv igjen om litt.',
            'unexpected_response' => 'TED returnerte et uventet svar. Prøv igjen om litt.',
            default => 'TED-søket kunne ikke fullføres. Prøv igjen om litt.',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
