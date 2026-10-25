<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chave de API do Postal
    |--------------------------------------------------------------------------
    |
    | Crie em "Chaves de API", no painel (começa com "ps_"). Para um sistema que só envia e-mails
    | e eventos, use uma chave só de envio. Nunca coloque a chave no código.
    |
    */

    'key' => env('POSTAL_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Opções do cliente
    |--------------------------------------------------------------------------
    */

    'base_url' => env('POSTAL_API_URL', 'https://api.postal.so'),

    'max_retries' => 2,

    'timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | O signing_secret (whsec_...) do webhook, usado por Postal\Webhook::verify().
    |
    */

    'webhook_secret' => env('POSTAL_WEBHOOK_SECRET'),

];
