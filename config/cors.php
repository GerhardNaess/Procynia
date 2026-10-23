<?php

/*
 * No cross-origin access. The external API is called server to server (Siggjo's sync job and
 * agents), never from a browser on another origin, so it answers no preflight and sends no
 * Access-Control-Allow-* header. Laravel's own default would open api/* to every origin.
 */
return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
