<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Centralizes "return to the Wiki tab the write request came from" for every Enterprise Wiki
 * controller action that redirects to the index (app.wiki.index) — never redirect()->back()
 * (which can send the user to an external referer or an unstable history entry), and never a
 * per-action, hand-built destination URL.
 *
 * The frontend sends the active tab explicitly as a "tab" request value (query string or body —
 * either works via Request::input()). An unknown or manipulated value is never trusted: it falls
 * back to the standard tab (self::DEFAULT_WIKI_TAB), the same rule WikiController::index() itself
 * applies when resolving which tab to render. The destination is always this app's own named
 * route with a whitelisted tab value, so this can never become an open redirect.
 */
trait RedirectsToWikiIndexTab
{
    public const WIKI_TABS = ['pages', 'sources', 'runs', 'quality'];

    public const DEFAULT_WIKI_TAB = 'pages';

    /**
     * Per-tab filter query keys that are safe to forward unchanged when the incoming request
     * already carries them — this is what lets an active Kjøringer/Kildedokumenter filter survive
     * a write action instead of being dropped. Values are re-validated by WikiController::index()'s
     * own tab loaders on the next render, so an unexpected value here is simply ignored there, not
     * a new attack surface.
     *
     * @var array<string, list<string>>
     */
    private const WIKI_TAB_FILTER_KEYS = [
        'runs' => ['run_status', 'run_decision', 'run_src'],
        'sources' => ['src_q', 'src_status'],
    ];

    protected function resolveWikiReturnTab(Request $request): string
    {
        $tab = $request->input('tab');

        return in_array($tab, self::WIKI_TABS, true) ? $tab : self::DEFAULT_WIKI_TAB;
    }

    protected function redirectToWikiTab(Request $request): RedirectResponse
    {
        $tab = $this->resolveWikiReturnTab($request);

        $filterParams = [];
        foreach (self::WIKI_TAB_FILTER_KEYS[$tab] ?? [] as $key) {
            $value = $request->input($key);

            if ($value !== null && $value !== '') {
                $filterParams[$key] = $value;
            }
        }

        return redirect()->route('app.wiki.index', ['tab' => $tab] + $filterParams);
    }
}
