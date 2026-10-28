<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Postal API key
    |--------------------------------------------------------------------------
    |
    | Create one under "API keys" in the dashboard (it starts with "ps_"). For a system that only sends emails
    | and events, use a sending-only key. Never put the key in code.
    |
    */

    'key' => env('POSTAL_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Client options
    |--------------------------------------------------------------------------
    */

    'base_url' => env('POSTAL_API_URL', 'https://api.postal.so'),

    /*
    | Extra attempts on 429 and 5xx (and on network failures, when repeating is safe). Default: 2; 0 turns it off.
    */

    'max_retries' => (int) env('POSTAL_MAX_RETRIES', 2),

    /*
    | Seconds each attempt may take, from start to finish. Default: 30. Keep it high if you
    | send large attachments (up to 40 MB).
    */

    'timeout' => (float) env('POSTAL_TIMEOUT', 30),

    /*
    | Seconds just to open the connection (DNS, TCP and TLS). Default: 5; 0 turns off the dedicated limit.
    | With the API down, a call blocks at most about (max_retries + 1) x connect_timeout
    | plus the waits between attempts. In synchronous web requests, consider timeout 10 and max_retries 1.
    */

    'connect_timeout' => (float) env('POSTAL_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | The webhook signing_secret (whsec_...), used by Postal\Webhook::verify().
    |
    */

    'webhook_secret' => env('POSTAL_WEBHOOK_SECRET'),

];
