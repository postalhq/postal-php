# postal-so/postal-php

[Português (pt-BR)](README.pt-BR.md) · English

Official [Postal](https://postal.so) SDK for PHP: send emails, keep contacts, fire product events and verify webhooks. Includes an optional Laravel bridge, with a `postal` mail transport (`MAIL_MAILER=postal`).

- PHP 8.2 or newer.
- No required dependencies: it uses `ext-curl` and `ext-json`.
- Typed errors, idempotency keys and automatic retries on 429 and 5xx.

API documentation: <https://postal.so/en/docs>. Guide to this SDK: <https://postal.so/en/docs/sdks>.

## Installation

```bash
composer require postal-so/postal-php
```

## First send

Create an API key under **API keys** in the dashboard (it starts with `ps_`), store it in an environment variable and verify the domain you send from.

```php
use Postal\Client;

$postal = new Client(getenv('POSTAL_API_KEY')); // or new Client() to read POSTAL_API_KEY by itself

$result = $postal->emails->send([
    'from' => 'Acme Store <you@yourdomain.com>',
    'to' => 'jane@example.com',
    'subject' => 'Welcome!',
    'html' => '<p>We are glad to have you here.</p>',
    'text' => 'We are glad to have you here.',
]);

echo $result['id'];
```

Do not use the key in a browser or an app: call Postal from your server. For systems that only send emails and events, create a **sending-only** key.

Fields have the same names as the API (`reply_to`, `scheduled_at`, `external_id`...) and responses are arrays, so the documentation at <https://postal.so/en/docs/api> applies directly to the SDK.

### Client options

```php
$postal = new Client('ps_xxx', [
    'base_url' => 'https://api.postal.so', // default
    'max_retries' => 2,                    // extra attempts on 429 and 5xx; 0 turns it off
    'timeout' => 30,                       // seconds per attempt, from start to finish
    'connect_timeout' => 5,                // seconds just to open the connection; 0 turns it off
    'retry_delay' => 500,                  // base wait in ms, doubles on every attempt
]);
```

### Timeouts and retries

| Option | Default | What it limits |
| --- | --- | --- |
| `timeout` | 30 s | The whole attempt: connection, sending the body and reading the response. Keep it high if you send large attachments (up to 40 MB). |
| `connect_timeout` | 5 s | Only opening the connection (DNS, TCP and TLS), through `CURLOPT_CONNECTTIMEOUT_MS`. If it runs out, it becomes a `NetworkException` with the name `connect_timeout`. `0` turns it off. |
| `max_retries` | 2 | Extra attempts (3 in total). `0` turns it off. |

The worst case for a call to block is:

```
(max_retries + 1) × time of each attempt + waits between attempts
```

The wait starts at 500 ms and doubles on every attempt, with up to 25% extra (with the defaults, about 1.9 s in total at most). On a 429, the wait is the `Retry-After` (up to 30 s per attempt). With the defaults:

- **API unreachable** (the connection does not open): `3 × 5 s + ~1.9 s`, about **17 s**.
- **API accepts the connection but does not answer**: `3 × 30 s + ~1.9 s`, about **92 s**.

Recommendations:

- **Synchronous web request** (someone waiting for the page): `timeout` 10 and `max_retries` 1. With the API unreachable this blocks about 11 s (`2 × 5 s + ~0.6 s`; use `connect_timeout` 3 for ~7 s) and at most about 21 s if it hangs after connecting. If the request sends large attachments, keep `timeout` higher or send through a queue.
- **Queued job** (nobody waiting): keep the defaults. Or better, let the queue itself retry the job.

```php
$postal = new Client(getenv('POSTAL_API_KEY'), ['timeout' => 10, 'max_retries' => 1]);
```

In Laravel, the same options live in `config/postal.php` and in the `POSTAL_TIMEOUT`, `POSTAL_CONNECT_TIMEOUT` and `POSTAL_MAX_RETRIES` variables.

## Emails

```php
// With a published template: from, subject and reply_to come from the template
$postal->emails->send([
    'to' => 'jane@example.com',
    'template' => ['id' => 'welcome', 'variables' => ['NAME' => 'Jane']],
]);

// Scheduled (up to 30 days), with an attachment and a tag
$result = $postal->emails->send([
    'from' => 'you@yourdomain.com',
    'to' => ['jane@example.com'],
    'subject' => 'Your invoice',
    'html' => '<p>Attached.</p>',
    'attachments' => [['filename' => 'invoice.pdf', 'content' => base64_encode($pdf)]],
    'tags' => [['name' => 'category', 'value' => 'billing']],
    'scheduled_at' => new DateTimeImmutable('+1 hour'),
]);

$postal->emails->update($result['id'], '2026-10-26T09:00:00-03:00'); // reschedules
$postal->emails->cancel($result['id']);                               // cancels

// Up to 100 emails in one request: either all are queued, or none
$postal->emails->batch([
    ['from' => 'you@yourdomain.com', 'to' => 'ann@example.com', 'subject' => 'Your coupon', 'html' => '<p>WELCOME</p>'],
    ['from' => 'you@yourdomain.com', 'to' => 'bea@example.com', 'subject' => 'Your coupon', 'html' => '<p>WELCOME</p>'],
]);

$email = $postal->emails->get($result['id']); // status in last_event and history in events
$page = $postal->emails->list(['status' => ['bounced', 'complained'], 'limit' => 50]);
```

`emails->list` and the other read methods need a full-access key. Two reads also work with a sending-only key: `emails->get` (only the emails it sent) and `usage()` (without the verification credits).

### One-time codes (`OTP-Token`)

The `OTP-Token` header ([draft-goto-otp-token](https://datatracker.ietf.org/doc/draft-goto-otp-token/)) delivers the code of a verification email in a machine-readable way, bound to an origin, so that the mail client offers it to the page that asked for it. The SDK sends `headers` as they are (without encoding the quotes, in `send`, `batch`, scheduled sends and with a template, and also through the Laravel transport); `OtpToken::header($code, $origin)` builds the value with the right escaping:

```php
use Postal\OtpToken;

$postal->emails->send([
    'from' => 'you@yourdomain.com',
    'to' => 'jane@example.com',
    'subject' => 'Your verification code',
    'html' => '<p>Your code: <b>123456</b></p>',
    'headers' => ['OTP-Token' => OtpToken::header('123456', 'https://example.com')], // "123456"; origin="https://example.com"
]);
```

The code must be printable ASCII; the origin, the canonical one of whoever asks for the code (`https://host[:port]` in lowercase, no path, no trailing slash and no default port; `http` only on `localhost`). If it will not do, `OtpToken::header` throws `PostalException` (`getErrorName()` = `validation_error`, `getStatusCode()` = 0) before the API refuses the send with 422. Pass the header only once.

### Idempotency

To repeat a send without emailing twice, pass a key that identifies the *reason* for the send (not the attempt):

```php
$postal->emails->send($params, 'order-10482-shipped');
```

Without a key, the SDK generates one (a UUID) for each call of `emails->send`, `emails->batch` and `events->send` and reuses it on retries, so a timeout, a connection failure or a 5xx does not duplicate the send. The other `POST` and `PATCH` calls (such as `contacts->create`, `segments->create` and `emails->cancel`) carry no key: the SDK only repeats them on 429 (the API refused before processing), never after a 5xx or a network failure, because repeating could duplicate the effect. `contacts->upsert` and `contacts->batch` are safe to repeat and are therefore repeated. See [Idempotency](https://postal.so/en/docs/idempotencia).

## Contacts

`external_id` is the person's ID in your system. `upsert` creates or updates the contact looking it up by `id`, then `external_id`, then `email`, and repeating the call is safe.

```php
$result = $postal->contacts->upsert([
    'external_id' => 'user_42',
    'email' => 'jane@example.com', // required to create a new contact
    'first_name' => 'Jane',
    'properties' => ['plan' => 'pro'], // the property must exist beforehand
]);
echo $result['status']; // created, updated or unchanged

$contact = $postal->contacts->getByExternalId('user_42');
$postal->contacts->update('jane@example.com', ['unsubscribed' => true]);
$postal->contacts->delete($contact['id']);

foreach ($postal->contacts->iterate(['search' => 'ann']) as $contact) {
    echo $contact['email']; // walks through all the pages
}
```

Someone who unsubscribed only receives again if you send `resubscribe => ['consented_at' => ..., 'proof' => ...]` with proof of the new consent. `contacts->batch([...])` does the same for many contacts at once.

## Events

Events tell Postal what happens in your product and start the automations with that trigger. They work with a sending-only key.

```php
$postal->events->send([
    'name' => 'signup',
    'contact' => 'jane@example.com', // a new email creates the contact
    'properties' => ['plan' => 'free', 'source' => 'site'],
]);

$postal->events->send([
    'name' => 'order.paid',
    'contact' => 'external_id:user_42',
    'properties' => ['total' => 199.9, 'currency' => 'USD'],
    'contact_properties' => ['plan' => 'pro'], // updates the contact before the automations
], 'order-10482-paid');
```

## Segments, topics and domains

```php
$segment = $postal->segments->create(['name' => 'Active customers']);
$postal->contacts->addToSegment('jane@example.com', $segment['id']);

$postal->topics->create(['name' => 'News', 'default_subscription' => 'opt_in', 'visibility' => 'public']);

$domains = $postal->domains->list();
$postal->domains->verify($domains['data'][0]['id']);     // checks DNS now
$domain = $postal->domains->get($domains['data'][0]['id']); // records and the status of each
```

## Webhooks

Register the endpoint in the dashboard or with `$postal->webhooks->create(['endpoint' => ..., 'events' => [...]])` and keep the `signing_secret` (`whsec_...`). `Webhook::verify($payload, $headers, $secret)` checks the signature (Standard Webhooks: HMAC-SHA256 of `{webhook-id}.{webhook-timestamp}.{body}`), refuses messages more than 5 minutes off and returns the event. Use the **raw** body, exactly as it arrived.

```php
use Postal\Exceptions\WebhookVerificationException;
use Postal\Webhook;

try {
    $event = Webhook::verify(
        file_get_contents('php://input'),
        getallheaders(),
        getenv('POSTAL_WEBHOOK_SECRET'),
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'email.bounced') {
    // drop the address in $event['data']['to']
}

http_response_code(200);
```

In Laravel, in a route outside the `web` group (no CSRF):

```php
Route::post('/webhooks/postal', function (Request $request) {
    $event = Webhook::verify($request->getContent(), $request->headers->all(), config('postal.webhook_secret'));

    // ...

    return response()->noContent();
});
```

Use the `webhook-id` header to discard repeated deliveries. See [Webhooks](https://postal.so/en/docs/webhooks).

## Errors

Every error response from Postal becomes a `Postal\Exceptions\PostalException` with the status, the `name` (a stable code in English, such as `validation_error`) and Postal's message. Handle it by `name`, not by message.

```php
use Postal\Exceptions\PostalException;

try {
    $postal->emails->send($params);
} catch (PostalException $e) {
    $e->getStatusCode(); // 422
    $e->getErrorName();  // 'validation_error'
    $e->getMessage();    // 'Missing `subject` field.'

    if ($e->getErrorName() === 'daily_quota_exceeded') {
        // the daily quota ran out; it comes back at 00:00 UTC
    }
}
```

Network failures and timeouts become `NetworkException` (status 0; name `network_error`, `timeout` or `connect_timeout`), which is also a `PostalException`. The codes are at <https://postal.so/en/docs/erros>.

### Retries

- `429 rate_limit_exceeded` is always repeated, waiting for the `Retry-After`. An exhausted quota (`daily_quota_exceeded`, `monthly_quota_exceeded`) is not repeated.
- `5xx`, `409 concurrent_idempotent_requests` and network failures are repeated on `GET`, `DELETE`, on sends with an idempotency key (the automatic one included) and on `contacts->upsert`. On a `POST` without a key, such as `contacts->create`, they are not: repeating could duplicate the effect.
- The wait starts at 500 ms and doubles on every attempt (30 s at most).
- A connection failure or timeout (`connect_timeout`) counts as a network failure and follows the same rule.

### Rate limit

`$postal->rateLimit()` returns the requests-per-second limit read from the headers of the last response (`X-RateLimit-Limit`, `X-RateLimit-Remaining` and, on a 429, `Retry-After` and `X-RateLimit-Reset`). Unlike `$postal->usage()`, it works with a **sending-only** key and makes no extra request.

```php
$postal->emails->send($params);

$rateLimit = $postal->rateLimit(); // null until a response with the headers arrives
// Postal\RateLimit { limit: 10, remaining: 9, retryAfter: null, resetAt: null }

if ($rateLimit !== null && $rateLimit->remaining === 0) {
    // slow down the bulk send
}
```

The (`readonly`) properties are `limit` and `remaining` (requests per second), and `retryAfter` (seconds) and `resetAt` (Unix timestamp), which only come on a 429. Whatever did not come is `null`. Error responses count too: the `PostalException` of a 429 carries the same object in `$e->rateLimit`. A response without the headers keeps the last known value.

## Laravel

The package registers itself (auto-discovery). It creates the `Postal\Client` in the container, reading `POSTAL_API_KEY`, and the `postal` mail transport.

```php
use Postal\Client;

Route::post('/invitations', function (Client $postal) {
    $postal->events->send(['name' => 'invite.sent', 'contact' => request('email')]);
});
```

### Send Laravel's emails through Postal

1. Set the key in `.env`:

   ```dotenv
   MAIL_MAILER=postal
   MAIL_FROM_ADDRESS=you@yourdomain.com
   MAIL_FROM_NAME="Acme Store"
   POSTAL_API_KEY=ps_xxx
   ```

2. In `config/mail.php`, inside `mailers`, add:

   ```php
   'postal' => [
       'transport' => 'postal',
       // 'key' => env('POSTAL_API_KEY'), // optional: a key just for this mailer
   ],
   ```

That is it: `Mail::to(...)->send(...)`, email notifications and `Mailable`s now go out through the Postal API. The domain of `MAIL_FROM_ADDRESS` must be verified. Attachments, inline images, `Cc`, `Bcc`, `Reply-To`, extra headers and tags go along (the `Mailable`'s `tag()` and `metadata()` become tags of the send). The email ID in Postal is in `$sentMessage->getMessageId()`.

The transport uses `symfony/mailer`, which already comes with Laravel. It is only a suggestion (`suggest`) of the package, so that those who do not use Laravel do not have to install it.

To publish the configuration file (optional):

```bash
php artisan vendor:publish --tag=postal-config
```

## Tests

```bash
composer install
vendor/bin/phpunit
```

The tests use a fake HTTP transport and, for `CurlTransport`, a local server (`php -S`). To swap the HTTP transport in your code, pass a class that implements `Postal\Http\Transport` in the `transport` option (the `send` method receives the `timeout` and the `connect_timeout`, in that order, after the body).

## License

MIT
