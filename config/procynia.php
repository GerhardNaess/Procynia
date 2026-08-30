<?php

return [
    'health_token' => env('PROCYNIA_HEALTH_TOKEN'),

    'ai' => [
        'usage_guard' => [
            'user_per_minute' => env('AI_RATE_LIMIT_USER_PER_MINUTE', 5),
            'user_decay_seconds' => env('AI_RATE_LIMIT_USER_DECAY_SECONDS', 60),
        ],
        'wiki_ask' => [
            // A question can result in a retrieval-plan call plus an answer call. Ten questions per
            // user per 15 minutes prevents accidental loops, while sixty per customer preserves
            // normal collaboration without permitting an unbounded tenant-wide cost vector.
            'window_seconds' => env('AI_WIKI_ASK_WINDOW_SECONDS', 900),
            'user_attempts' => env('AI_WIKI_ASK_USER_ATTEMPTS', 10),
            'customer_attempts' => env('AI_WIKI_ASK_CUSTOMER_ATTEMPTS', 60),
        ],
        'quota' => [
            // Customer-facing warning levels for the commercial AI-case quota. 100% is not
            // configurable: it is the hard stop the reservation ledger already enforces, so a
            // separate number here could only ever disagree with what actually blocks the customer.
            'warning_percent' => env('AI_QUOTA_WARNING_PERCENT', 80),
            'critical_percent' => env('AI_QUOTA_CRITICAL_PERCENT', 90),
        ],
    ],

    'security' => [
        /*
         * Proxies whose X-Forwarded-* headers Laravel is allowed to believe.
         *
         * Empty by default, which means: trust nothing, and take the client IP from REMOTE_ADDR —
         * the peer nginx actually accepted the connection from. A client cannot forge that.
         *
         * This replaces trustProxies(at: '*'). With '*' every peer counted as a trusted proxy, so an
         * arbitrary X-Forwarded-For sent by the browser became the client IP. That made the /login
         * rate limiter (keyed on email + IP) bypassable by rotating the header.
         *
         * Set TRUSTED_PROXIES to the exact address or CIDR of the proxy in front of the container —
         * not to a broad private range. The container is reached through Docker's published port, so
         * its peer is always the Docker gateway; trusting a whole RFC1918 range would trust every
         * caller on that network, which is the same hole in a smaller shape.
         *
         *   Local development : leave unset. There is no legitimate upstream proxy.
         *   On-prem production: the host reverse proxy as the container sees it (the Docker gateway).
         *                       Required for HTTPS detection and real client IPs — see
         *                       docs/operations/production-deploy.md.
         *   Azure             : the Container Apps ingress range. Not yet verified — see
         *                       docs/operations/security.md.
         *
         * "*" is accepted but reintroduces the original weakness. Do not use it.
         */
        'trusted_proxies' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
            static fn (string $value): bool => $value !== '',
        )),

        /*
         * Global HTTP security headers (security finding F-05). Applied by
         * App\Http\Middleware\AddSecurityHeaders.
         *
         * The policy lives here rather than in the middleware so it can be reviewed as a value
         * instead of read out of control flow. Nothing here is env-overridable: these are security
         * controls, and a policy that can be quietly weakened per environment will be.
         */
        'headers' => [
            /*
             * How long a browser should remember to use HTTPS. Only ever sent on requests Laravel
             * considers secure, so local HTTP development never receives it and cannot be locked
             * into HTTPS on localhost.
             *
             * Deliberately without `preload`: preloading is a one-way door — removal from the browser
             * preload list takes months — and it is an operational decision, not a code one.
             * `includeSubDomains` is also left off until every Procynia subdomain is known to be
             * HTTPS-only; adding it early would break any plain-HTTP subdomain that still exists.
             */
            'hsts_max_age' => 31536000,

            /*
             * Browser features Procynia never uses. Denying them means a future XSS (or a
             * compromised dependency) cannot silently reach for a camera or a payment handler.
             * Stripe runs server-side only, so `payment` is safe to deny here.
             */
            'permissions_policy' => 'accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), usb=(), xr-spatial-tracking=()',

            /*
             * Content-Security-Policy, assembled per response.
             *
             * `base` applies to every HTML response. `script-src 'self'` with no unsafe- keywords is
             * the point of the whole exercise: the customer frontend is Inertia + React, whose
             * production output is linked module scripts only, so it does not need inline script or
             * eval.
             *
             * `style-src` does allow 'unsafe-inline'. Filament injects <style> blocks, and this is
             * tracked as residual risk below. Inline *style* is a far smaller problem than inline
             * script: it cannot execute JavaScript.
             *
             * `frame-ancestors 'none'` means nothing may frame Procynia; it pairs with
             * X-Frame-Options: DENY. `frame-src 'self'` is separate and is required — the AI
             * document preview frames a same-origin PDF (resources/js/Pages/App/AI/DocumentPreview.jsx).
             */
            'csp' => [
                'base' => [
                    'default-src' => "'self'",
                    'base-uri' => "'self'",
                    'object-src' => "'none'",
                    'frame-ancestors' => "'none'",
                    'form-action' => "'self'",
                    'script-src' => "'self'",
                    'style-src' => "'self' 'unsafe-inline'",
                    'img-src' => "'self' data: blob:",
                    'font-src' => "'self' data:",
                    'connect-src' => "'self'",
                    'frame-src' => "'self'",
                    'worker-src' => "'self' blob:",
                    'manifest-src' => "'self'",
                ],

                /*
                 * Filament needs more than the customer frontend does, and pretending otherwise
                 * would just mean shipping a policy that breaks the admin panel.
                 *
                 * - 'unsafe-inline': Filament renders inline <script> blocks (dark-mode bootstrap,
                 *   window.filamentData). It has no nonce mechanism to hook into.
                 * - 'unsafe-eval': Alpine, bundled inside livewire.js, evaluates directives with
                 *   `new Function`/AsyncFunction.
                 *
                 * Residual risk, mitigated by /admin being internal-admin-only (finding F-03) and by
                 * the customer-facing surface keeping the strict policy.
                 */
                'admin_script_src' => "'self' 'unsafe-inline' 'unsafe-eval'",

                /*
                 * Vite's dev server, for local development only.
                 *
                 * Note what is NOT here: `http://[::1]:5173`. CSP's host-source grammar has no way to
                 * express an IPv6 literal, and a browser discards the whole token —
                 * "contains an invalid source: 'http://[::1]:5173'. It will be ignored." Vite binds
                 * to localhost, which resolves to ::1 on macOS, so laravel-vite-plugin writes exactly
                 * that origin into public/hot and the browser then requests it. An enforcing CSP
                 * therefore cannot admit the dev server on such a host, which is why development
                 * runs the policy in report-only mode (see 'enforce_in_development' below).
                 *
                 * These entries still matter: they keep the report-only output quiet for developers
                 * whose Vite resolves to an IPv4 host.
                 */
                'dev_script_origins' => [
                    'http://localhost:5173',
                    'http://127.0.0.1:5173',
                ],

                'dev_connect_origins' => [
                    'http://localhost:5173',
                    'http://127.0.0.1:5173',
                    'ws://localhost:5173',
                    'ws://127.0.0.1:5173',
                ],

                /*
                 * Enforce in production, report-only while the Vite dev server is running.
                 *
                 * This is not a way to dodge a hard policy: production — the surface that matters —
                 * gets an enforcing Content-Security-Policy. Locally, an enforcing policy would block
                 * Vite on an IPv6 host and leave the developer with a blank page, so the same policy
                 * is sent as Content-Security-Policy-Report-Only instead. Violations still show up in
                 * the browser console, so a developer introducing a pattern that would break
                 * production still sees it.
                 */
                'enforce_in_development' => false,
            ],
        ],
    ],

    'auth' => [
        /*
         * Brute-force protection for POST /login (security finding F-01).
         *
         * Five attempts per minute, per (email + client IP). The window is a decaying rate limit,
         * not an account lock: it expires on its own, and a successful login clears it immediately.
         * Nothing here can lock a user out permanently, which is deliberate — a permanent lock would
         * hand an attacker a denial-of-service primitive against any account whose address they know.
         *
         * These values are intentionally NOT env-overridable. They are a security control, and a
         * limit that can be quietly raised per environment is a limit that will be.
         */
        'login' => [
            'max_attempts' => 5,
            'decay_seconds' => 60,
        ],

        /*
         * Microsoft Entra ID / SSO.
         *
         * Two independent switches, deliberately not one mode enum. A customer migrating to SSO
         * needs a window where both work, and Procynia must keep supporting customers who have no
         * Entra tenant at all.
         *
         *   entra_enabled = false, local_login_enabled = true   today's behaviour, local dev
         *   entra_enabled = true,  local_login_enabled = true   migration window
         *   entra_enabled = true,  local_login_enabled = false  SSO only
         *   entra_enabled = false, local_login_enabled = false  refused — see below
         *
         * Turning both off would lock everyone out, so App\Services\Auth\EntraConfig treats that
         * as a configuration error rather than silently applying it.
         *
         * Neither is derived from app()->environment(): production must be able to run either mode,
         * and a developer must be able to exercise the Entra path locally against a test tenant.
         */
        'entra_enabled' => (bool) env('AUTH_ENTRA_ENABLED', false),
        'local_login_enabled' => (bool) env('AUTH_LOCAL_LOGIN_ENABLED', true),

        'entra' => [
            /*
             * The client secret. The only secret here — tenant id, client id and issuer are
             * configuration and live in the identity_providers table.
             *
             * Read through config rather than env() at the point of use, so a Key Vault or other
             * secret injector can supply it later without touching the OIDC code.
             */
            'client_secret' => env('AUTH_ENTRA_CLIENT_SECRET'),

            /*
             * Absolute callback URL registered in the Entra app registration. Must be HTTPS outside
             * local development; RuntimePreflightService enforces that.
             */
            'redirect_uri' => env('AUTH_ENTRA_REDIRECT_URI'),

            /*
             * openid: authenticate at all. profile: display name. email: the address used for the
             * one-time linking decision. Nothing beyond that — Procynia reads no directory data and
             * calls no Graph endpoint, so a wider scope would be permission it never uses.
             */
            'scopes' => ['openid', 'profile', 'email'],

            /*
             * Clock skew allowed when validating `exp`/`nbf`, in seconds. Small on purpose.
             */
            'leeway_seconds' => 60,
        ],
    ],

    'backup' => [
        /*
         * Does THIS runtime support the legacy, Compose-based backup mechanism?
         *
         * scripts/backup-production.sh runs `docker compose exec -T postgres pg_dump`, which needs a
         * Docker CLI and a Compose project. Azure Container Apps has neither, so the whole mechanism
         * has to be off there. Azure PostgreSQL Flexible Server provides automated backup and
         * point-in-time restore instead; there is deliberately no Laravel replacement.
         *
         * This is NOT the same question as backup_settings.backup_enabled in the database. That flag
         * answers "has an operator switched backup on?". This one answers "can this runtime execute
         * the Compose mechanism at all?" — and it must win, because a database migrated to Azure can
         * arrive with backup_enabled = true.
         *
         * Default true: an unset value means an existing Compose deployment, whose behaviour must not
         * change. Azure sets it explicitly to false (see infra/main.bicep). filter_var is used rather
         * than a plain cast so that "false", "0" and "off" are all honoured — (bool) "false" is true.
         */
        'legacy_enabled' => filter_var(
            env('PROCYNIA_LEGACY_BACKUP_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'directory' => env('BACKUP_DIRECTORY', '/backup/procynia'),
        'rpo_hours' => 1,
        'scheduler_heartbeat_stale_seconds' => 3600,
    ],

    'public' => [
        'contact' => [
            'general_email' => env('PROCYNIA_CONTACT_EMAIL', 'kontakt@procynia.no'),
            'sales_email' => env('PROCYNIA_SALES_EMAIL', 'salg@procynia.no'),
            'support_email' => env('PROCYNIA_SUPPORT_EMAIL', 'support@procynia.no'),
            'privacy_email' => env('PROCYNIA_PRIVACY_EMAIL', 'personvern@procynia.no'),
            'phone' => env('PROCYNIA_PHONE', '+47 00 00 00 00'),
        ],
    ],
];
