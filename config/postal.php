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

    /*
    | Tentativas extras em 429 e 5xx (e em falhas de rede, quando repetir é seguro). Padrão: 2; 0 desliga.
    */

    'max_retries' => (int) env('POSTAL_MAX_RETRIES', 2),

    /*
    | Segundos que cada tentativa pode levar, do início ao fim. Padrão: 30. Mantenha alto se você
    | envia anexos grandes (até 40 MB).
    */

    'timeout' => (float) env('POSTAL_TIMEOUT', 30),

    /*
    | Segundos só para abrir a conexão (DNS, TCP e TLS). Padrão: 5; 0 desliga o limite próprio.
    | Com a API fora do ar, uma chamada bloqueia no máximo cerca de (max_retries + 1) x connect_timeout
    | mais as esperas entre tentativas. Em requisições web síncronas, considere timeout 10 e max_retries 1.
    */

    'connect_timeout' => (float) env('POSTAL_CONNECT_TIMEOUT', 5),

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
