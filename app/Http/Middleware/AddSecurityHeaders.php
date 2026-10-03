<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global HTTP security headers (security finding F-05).
 *
 * Procynia sent none of these before: no CSP, no HSTS, no framing or sniffing protection on any
 * route. This middleware adds them in one place so the policy is the same whether a response comes
 * from the customer frontend, Filament, an API route or a file download — and so it holds locally as
 * well as behind nginx, rather than existing only in a production web-server config.
 *
 * Scope split, deliberate:
 *   - The simple headers go on every response. They are cheap and cannot break a JSON body or a
 *     download.
 *   - Content-Security-Policy goes on HTML responses only. It governs how a browser executes a
 *     document; putting it on a PDF stream or a JSON payload adds bytes and no protection.
 *
 * The policy itself lives in config('procynia.security.headers') so it can be read as a value.
 * This class only decides which variant applies to the request in hand.
 *
 * It does not touch the body, cookies, the session or authentication.
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = (array) config('procynia.security.headers', []);

        // Never let a browser guess a content type. Without this, a file a user uploaded and a
        // browser decided was HTML becomes script on our origin.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Send the full URL only to ourselves. Cross-origin requests get the bare origin, and an
        // HTTPS -> HTTP downgrade gets nothing. Procynia URLs carry case and document identifiers,
        // which should not travel to third parties in a Referer header.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Nothing in Procynia is designed to be framed by another site. DENY rather than SAMEORIGIN:
        // the one iframe in the product (the AI document preview) frames our own URL from our own
        // page, which X-Frame-Options does not govern — that is frame-src in the CSP below.
        $response->headers->set('X-Frame-Options', 'DENY');

        if (isset($headers['permissions_policy'])) {
            $response->headers->set('Permissions-Policy', (string) $headers['permissions_policy']);
        }

        // HSTS only when the request actually arrived over HTTPS. Sending it over plain HTTP is
        // ignored by browsers anyway, but emitting it in local development risks pinning
        // http://localhost to HTTPS in a developer's browser, which is painful to undo.
        //
        // isSecure() honours X-Forwarded-Proto only for proxies in the trusted list configured in
        // AppServiceProvider, so a client cannot fake it into existence.
        if ($request->isSecure() && ! empty($headers['hsts_max_age'])) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) $headers['hsts_max_age'],
            );
        }

        if ($this->isHtmlResponse($response)) {
            // Always enforcing, development included. A report-only policy cannot deliver
            // frame-ancestors at all and makes the browser ask for a report-to group that Procynia
            // has no endpoint for, so it costs two console warnings and buys nothing: the dev server
            // is reachable under the real policy now that its origin is an expressible one.
            $response->headers->set(
                'Content-Security-Policy',
                $this->contentSecurityPolicy($request, (array) ($headers['csp'] ?? [])),
            );
        }

        return $response;
    }

    /**
     * CSP belongs on documents a browser parses as HTML, not on JSON, PDFs or downloads.
     */
    private function isHtmlResponse(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type', ''), 'text/html');
    }

    /**
     * @param  array<string, mixed>  $csp
     */
    private function contentSecurityPolicy(Request $request, array $csp): string
    {
        /** @var array<string, string> $directives */
        $directives = (array) ($csp['base'] ?? []);

        // Filament renders inline scripts and bundles Alpine, which evaluates directives with
        // `new Function`. The strict script-src stays on the customer-facing surface.
        if ($this->isAdminPanelRequest($request)) {
            if (isset($csp['admin_script_src'])) {
                $directives['script-src'] = (string) $csp['admin_script_src'];
            }

        }

        // Local development serves modules and HMR over the Vite dev server, and @viteReactRefresh
        // emits an inline preamble. Neither exists in a production build, so none of this widens the
        // policy that ships.
        $viteOrigin = $this->viteDevServerOrigin();

        if ($viteOrigin !== null) {
            foreach (['script-src', 'style-src'] as $directive) {
                if (isset($directives[$directive])) {
                    $directives[$directive] = trim($directives[$directive].' '.$viteOrigin);
                }
            }

            // Only connect-src has any use for the HMR socket; putting ws: on script-src was noise.
            if (isset($directives['connect-src'])) {
                $directives['connect-src'] = trim(
                    $directives['connect-src'].' '.$viteOrigin.' '.$this->webSocketOrigin($viteOrigin),
                );
            }

            // @viteReactRefresh injects an inline preamble that only exists in dev.
            if (isset($directives['script-src']) && ! str_contains($directives['script-src'], "'unsafe-inline'")) {
                $directives['script-src'] .= " 'unsafe-inline'";
            }
        }

        $parts = [];

        foreach ($directives as $directive => $value) {
            $parts[] = trim($directive.' '.$value);
        }

        return implode('; ', $parts);
    }

    private function isAdminPanelRequest(Request $request): bool
    {
        return $request->is('admin', 'admin/*');
    }

    /**
     * The origin a running Vite dev server told the application to load assets from, or null.
     *
     * public/hot is the ground truth: laravel-vite-plugin writes the resolved dev server URL into it
     * on start and deletes it on exit, so reading it keeps the policy in step with the port Vite
     * actually got. Gating on the local environment as well means a stray hot file in a production
     * image cannot widen the policy, and the automated tests assert the policy that ships rather
     * than whatever a developer happens to have running.
     *
     * An IPv6-literal host is rejected rather than emitted. CSP's host-source grammar cannot express
     * one, so a browser would discard the entire token and quietly take the surrounding directive
     * down with it; vite.config.js pins the dev server to an IPv4 host so this does not arise.
     */
    private function viteDevServerOrigin(): ?string
    {
        if (! app()->environment('local')) {
            return null;
        }

        $hotFile = public_path('hot');

        if (! is_file($hotFile)) {
            return null;
        }

        $url = trim((string) file_get_contents($hotFile));
        $parts = parse_url($url);

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return null;
        }

        // parse_url() hands back IPv6 literals still wrapped in brackets.
        if (str_contains($host, ':')) {
            return null;
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * The same origin as a WebSocket source, for the HMR connection.
     */
    private function webSocketOrigin(string $origin): string
    {
        return str_starts_with($origin, 'https://')
            ? 'wss://'.substr($origin, 8)
            : 'ws://'.substr($origin, 7);
    }
}
