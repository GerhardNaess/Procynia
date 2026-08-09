<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Keeps the "Tilbake til funn" return context alive across a write action on a Wiki page.
 *
 * A page opened from one specific finding carries that finding in its URL (claim_id + back_url —
 * the back_url itself built by EnterpriseWikiRunFindingsService::returnUrlForFinding(), with
 * run_src/focus_run/focus_finding). Every action the reviewer then performs on that page ends in a
 * redirect, and a redirect that names only the slug silently drops both: the next render has no
 * review_reference, so the finding link disappears and the reviewer is left with the generic
 * "Tilbake til Wiki". Approving a suggestion is the middle of the workflow, not the end of it.
 *
 * Sharing this here rather than duplicating it means there is exactly ONE definition of what a
 * legal return URL is — WikiController (which reads it off the incoming request) and
 * WikiClaimController (which forwards it through its redirects) can never drift apart.
 *
 * The validation is deliberately a whitelist, not a sanitizer: only this app's own Wiki index with
 * tab=runs is ever accepted, so a caller-supplied back_url can never turn a redirect into an open
 * redirect to another host. Anything else degrades to null, which is exactly the pre-existing
 * "no finding context" behavior.
 */
trait PreservesWikiReviewReturnUrl
{
    protected function normalizeReviewBackUrl(string $backUrl): ?string
    {
        $backUrl = trim($backUrl);

        if ($backUrl === '') {
            return null;
        }

        $parsed = parse_url($backUrl);

        if (! is_array($parsed) || ($parsed['path'] ?? null) !== '/app/wiki') {
            return null;
        }

        // A host must belong to this application. parse_url() reports the path of
        // "https://evil.example.com/app/wiki?tab=runs" as "/app/wiki" just like a relative URL, so
        // the path check alone would accept an off-site destination — harmless-looking while this
        // value only fed a rendered <Link>, but it is now also forwarded into a server-side
        // redirect. "//evil.example.com/app/wiki" is covered by the same check.
        $host = $parsed['host'] ?? null;

        if ($host !== null && $host !== parse_url((string) config('app.url'), PHP_URL_HOST) && $host !== request()->getHost()) {
            return null;
        }

        parse_str($parsed['query'] ?? '', $query);

        return (($query['tab'] ?? null) === 'runs') ? $backUrl : null;
    }

    /**
     * Reads a back_url off the request (query string or body — a PATCH sends it in the body, the
     * initial GET has it in the query) and returns it only if it survives validation.
     */
    protected function reviewBackUrlFromRequest(Request $request): ?string
    {
        $raw = $request->input('back_url');

        return is_string($raw) ? $this->normalizeReviewBackUrl($raw) : null;
    }

    /**
     * Route parameters for an action that redirected to the BARE slug before. The review context is
     * all-or-nothing here: claim_id is what makes WikiController::show() build a review_reference at
     * all, and the back_url has nothing to attach to without it — so either both are carried or the
     * redirect keeps its original shape. A page opened straight from the Wiki list therefore
     * navigates exactly as it always did.
     *
     * @return array<string, mixed>
     */
    protected function wikiShowRouteParamsForReviewReturn(Request $request, string $slug, int $claimId): array
    {
        $backUrl = $this->reviewBackUrlFromRequest($request);

        return $backUrl === null
            ? ['slug' => $slug]
            : $this->wikiShowRouteParamsWithReviewContext($slug, $claimId, $backUrl);
    }

    /**
     * Route parameters for an action that already carried claim_id before this change — only the
     * back_url is added, so its redirect shape is otherwise untouched.
     *
     * @return array<string, mixed>
     */
    protected function wikiShowRouteParamsWithReviewContext(string $slug, ?int $claimId, ?string $backUrl): array
    {
        $parameters = ['slug' => $slug];

        if ($claimId !== null) {
            $parameters['claim_id'] = $claimId;
        }

        if ($backUrl !== null) {
            $parameters['back_url'] = $backUrl;
        }

        return $parameters;
    }
}
