# postal-so/postal-php

SDK oficial do [Postal](https://postal.so) para PHP: envie e-mails, mantenha contatos, dispare eventos de produto e confira webhooks. Inclui uma ponte opcional para o Laravel, com um transporte de e-mail `postal` (`MAIL_MAILER=postal`).

- PHP 8.2 ou mais novo.
- Sem dependências obrigatórias: usa `ext-curl` e `ext-json`.
- Erros tipados, chave de idempotência e retentativa automática em 429 e 5xx.

Documentação da API: <https://postal.so/docs>. Guia deste SDK: <https://postal.so/docs/sdks>.

## Instalação

```bash
composer require postal-so/postal-php
```

## Primeiro envio

Crie uma chave de API em **Chaves de API**, no painel (ela começa com `ps_`), guarde-a numa variável de ambiente e verifique o domínio de onde você envia.

```php
use Postal\Client;

$postal = new Client(getenv('POSTAL_API_KEY')); // ou new Client() para ler POSTAL_API_KEY sozinho

$result = $postal->emails->send([
    'from' => 'Minha empresa <voce@seudominio.com.br>',
    'to' => 'fulano@exemplo.com',
    'subject' => 'Bem-vindo!',
    'html' => '<p>Que bom ter você por aqui.</p>',
    'text' => 'Que bom ter você por aqui.',
]);

echo $result['id'];
```

Não use a chave no navegador nem em apps: chame o Postal a partir do seu servidor. Para sistemas que só disparam e-mails e eventos, crie uma chave **só de envio**.

Os campos têm os mesmos nomes da API (`reply_to`, `scheduled_at`, `external_id`...) e as respostas são arrays, então a documentação em <https://postal.so/docs/api> vale direto para o SDK.

### Opções do cliente

```php
$postal = new Client('ps_xxx', [
    'base_url' => 'https://api.postal.so', // padrão
    'max_retries' => 2,                    // tentativas extras em 429 e 5xx; 0 desliga
    'timeout' => 30,                       // segundos por tentativa, do início ao fim
    'connect_timeout' => 5,                // segundos só para abrir a conexão; 0 desliga
    'retry_delay' => 500,                  // espera base em ms, dobra a cada tentativa
]);
```

### Tempos limite e retentativas

| Opção | Padrão | O que limita |
| --- | --- | --- |
| `timeout` | 30 s | Cada tentativa inteira: conexão, envio do corpo e leitura da resposta. Mantenha alto se você envia anexos grandes (até 40 MB). |
| `connect_timeout` | 5 s | Só a abertura da conexão (DNS, TCP e TLS), via `CURLOPT_CONNECTTIMEOUT_MS`. Se estourar, vira `NetworkException` com o nome `connect_timeout`. `0` desliga. |
| `max_retries` | 2 | Tentativas extras (3 no total). `0` desliga. |

O pior caso de bloqueio de uma chamada é:

```
(max_retries + 1) × tempo de cada tentativa + esperas entre tentativas
```

A espera começa em 500 ms e dobra a cada tentativa, com até 25% a mais (com os padrões, no máximo cerca de 1,9 s no total). Num 429, a espera é o `Retry-After` (até 30 s por tentativa). Com os padrões:

- **API inalcançável** (a conexão não abre): `3 × 5 s + ~1,9 s`, cerca de **17 s**.
- **API aceita a conexão mas não responde**: `3 × 30 s + ~1,9 s`, cerca de **92 s**.

Recomendações:

- **Requisição web síncrona** (alguém esperando a página): `timeout` 10 e `max_retries` 1. Com a API inalcançável isso bloqueia cerca de 11 s (`2 × 5 s + ~0,6 s`; use `connect_timeout` 3 para ~7 s) e no máximo cerca de 21 s se ela travar depois de conectar. Se a requisição envia anexos grandes, mantenha o `timeout` mais alto ou envie por uma fila.
- **Job em fila** (ninguém esperando): fique com os padrões. Ou melhor, deixe a própria fila repetir o job.

```php
$postal = new Client(getenv('POSTAL_API_KEY'), ['timeout' => 10, 'max_retries' => 1]);
```

No Laravel, as mesmas opções ficam em `config/postal.php` e nas variáveis `POSTAL_TIMEOUT`, `POSTAL_CONNECT_TIMEOUT` e `POSTAL_MAX_RETRIES`.

## E-mails

```php
// Com template publicado: from, subject e reply_to vêm do template
$postal->emails->send([
    'to' => 'fulano@exemplo.com',
    'template' => ['id' => 'boas-vindas', 'variables' => ['NOME' => 'Fulano']],
]);

// Agendado (até 30 dias), com anexo e etiqueta
$result = $postal->emails->send([
    'from' => 'voce@seudominio.com.br',
    'to' => ['fulano@exemplo.com'],
    'subject' => 'Sua nota fiscal',
    'html' => '<p>Segue em anexo.</p>',
    'attachments' => [['filename' => 'nota.pdf', 'content' => base64_encode($pdf)]],
    'tags' => [['name' => 'categoria', 'value' => 'fiscal']],
    'scheduled_at' => new DateTimeImmutable('+1 hour'),
]);

$postal->emails->update($result['id'], '2026-10-26T09:00:00-03:00'); // reagenda
$postal->emails->cancel($result['id']);                               // cancela

// Até 100 e-mails numa requisição: ou todos entram na fila, ou nenhum
$postal->emails->batch([
    ['from' => 'voce@seudominio.com.br', 'to' => 'ana@exemplo.com', 'subject' => 'Seu cupom', 'html' => '<p>BEMVINDA</p>'],
    ['from' => 'voce@seudominio.com.br', 'to' => 'bia@exemplo.com', 'subject' => 'Seu cupom', 'html' => '<p>BEMVINDA</p>'],
]);

$email = $postal->emails->get($result['id']); // status em last_event e histórico em events
$page = $postal->emails->list(['status' => ['bounced', 'complained'], 'limit' => 50]);
```

`emails->get`, `list` e os demais métodos de leitura exigem uma chave de acesso total.

### Idempotência

Para repetir um envio sem mandar o e-mail duas vezes, passe uma chave que identifique o *motivo* do envio (não a tentativa):

```php
$postal->emails->send($params, 'pedido-10482-enviado');
```

Sem chave, o SDK gera uma (um UUID) para cada chamada de `emails->send`, `emails->batch` e `events->send` e a reaproveita nas retentativas, então um timeout, uma falha de conexão ou um 5xx não duplica o envio. Já os outros `POST` e `PATCH` (como `contacts->create`, `segments->create` e `emails->cancel`) não levam chave: o SDK só os repete em 429 (a API recusou antes de processar), nunca após um 5xx ou uma falha de rede, porque repetir poderia duplicar o efeito. `contacts->upsert` e `contacts->batch` são seguros de repetir e por isso são repetidos. Veja [Idempotência](https://postal.so/docs/idempotencia).

## Contatos

O `external_id` é o ID da pessoa no seu sistema. `upsert` cria ou atualiza o contato procurando por `id`, depois `external_id`, depois `email`, e repetir a chamada é seguro.

```php
$result = $postal->contacts->upsert([
    'external_id' => 'user_42',
    'email' => 'fulano@exemplo.com', // obrigatório para criar um contato novo
    'first_name' => 'Fulano',
    'properties' => ['plan' => 'pro'], // a propriedade precisa existir antes
]);
echo $result['status']; // created, updated ou unchanged

$contact = $postal->contacts->getByExternalId('user_42');
$postal->contacts->update('fulano@exemplo.com', ['unsubscribed' => true]);
$postal->contacts->delete($contact['id']);

foreach ($postal->contacts->iterate(['search' => 'ana']) as $contact) {
    echo $contact['email']; // percorre todas as páginas
}
```

Quem se descadastrou só volta a receber se você informar `resubscribe => ['consented_at' => ..., 'proof' => ...]` com a prova do novo consentimento. `contacts->batch([...])` faz o mesmo para muitos contatos de uma vez.

## Eventos

Eventos contam ao Postal o que acontece no seu produto e iniciam as automações com aquele gatilho. Funcionam com chave só de envio.

```php
$postal->events->send([
    'name' => 'signup',
    'contact' => 'fulano@exemplo.com', // um e-mail novo cria o contato
    'properties' => ['plan' => 'free', 'source' => 'site'],
]);

$postal->events->send([
    'name' => 'order.paid',
    'contact' => 'external_id:user_42',
    'properties' => ['total' => 199.9, 'currency' => 'BRL'],
    'contact_properties' => ['plan' => 'pro'], // atualiza o contato antes das automações
], 'order-10482-paid');
```

## Segmentos, tópicos e domínios

```php
$segment = $postal->segments->create(['name' => 'Clientes ativos']);
$postal->contacts->addToSegment('fulano@exemplo.com', $segment['id']);

$postal->topics->create(['name' => 'Novidades', 'default_subscription' => 'opt_in', 'visibility' => 'public']);

$domains = $postal->domains->list();
$postal->domains->verify($domains['data'][0]['id']);     // confere o DNS agora
$domain = $postal->domains->get($domains['data'][0]['id']); // registros e status de cada um
```

## Webhooks

Cadastre o endpoint no painel ou com `$postal->webhooks->create(['endpoint' => ..., 'events' => [...]])` e guarde o `signing_secret` (`whsec_...`). `Webhook::verify($payload, $headers, $secret)` confere a assinatura (Standard Webhooks: HMAC-SHA256 de `{webhook-id}.{webhook-timestamp}.{corpo}`), recusa mensagens com mais de 5 minutos de diferença e devolve o evento. Use o corpo **cru**, exatamente como chegou.

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
    // descarte o endereço em $event['data']['to']
}

http_response_code(200);
```

No Laravel, em uma rota fora do grupo `web` (sem CSRF):

```php
Route::post('/webhooks/postal', function (Request $request) {
    $event = Webhook::verify($request->getContent(), $request->headers->all(), config('postal.webhook_secret'));

    // ...

    return response()->noContent();
});
```

Use o cabeçalho `webhook-id` para descartar entregas repetidas. Veja [Webhooks](https://postal.so/docs/webhooks).

## Erros

Toda resposta de erro do Postal vira uma `Postal\Exceptions\PostalException` com o status, o `name` (código estável em inglês, como `validation_error`) e a mensagem do Postal. Trate pelo `name`, não pela mensagem.

```php
use Postal\Exceptions\PostalException;

try {
    $postal->emails->send($params);
} catch (PostalException $e) {
    $e->getStatusCode(); // 422
    $e->getErrorName();  // 'validation_error'
    $e->getMessage();    // 'Missing `subject` field.'

    if ($e->getErrorName() === 'daily_quota_exceeded') {
        // a cota diária acabou; volta às 00:00 UTC
    }
}
```

Falhas de rede e tempo esgotado viram `NetworkException` (status 0; nome `network_error`, `timeout` ou `connect_timeout`), que também é uma `PostalException`. Os códigos estão em <https://postal.so/docs/erros>.

### Retentativas

- `429 rate_limit_exceeded` é repetido sempre, esperando o `Retry-After`. Cota esgotada (`daily_quota_exceeded`, `monthly_quota_exceeded`) não é repetida.
- `5xx`, `409 concurrent_idempotent_requests` e falhas de rede são repetidos em `GET`, `DELETE`, nos envios com chave de idempotência (a automática inclusa) e em `contacts->upsert`. Num `POST` sem chave, como `contacts->create`, não: repetir poderia duplicar o efeito.
- A espera começa em 500 ms e dobra a cada tentativa (máximo de 30 s).
- Uma falha ou tempo esgotado de conexão (`connect_timeout`) conta como falha de rede e segue a mesma regra.

### Limite de requisições

`$postal->rateLimit()` devolve o limite de requisições por segundo lido dos cabeçalhos da última resposta (`X-RateLimit-Limit`, `X-RateLimit-Remaining` e, na 429, `Retry-After` e `X-RateLimit-Reset`). Diferente de `$postal->usage()`, funciona com chave **só de envio** e não faz requisição extra.

```php
$postal->emails->send($params);

$rateLimit = $postal->rateLimit(); // null até chegar uma resposta com os cabeçalhos
// Postal\RateLimit { limit: 10, remaining: 9, retryAfter: null, resetAt: null }

if ($rateLimit !== null && $rateLimit->remaining === 0) {
    // desacelere o envio em lote
}
```

As propriedades (`readonly`) são `limit` e `remaining` (requisições por segundo), e `retryAfter` (segundos) e `resetAt` (timestamp Unix) que só vêm na 429. O que não veio é `null`. Respostas de erro também contam: a `PostalException` de uma 429 traz o mesmo objeto em `$e->rateLimit`. Uma resposta sem os cabeçalhos mantém o último valor conhecido.

## Laravel

O pacote se registra sozinho (auto-discovery). Ele cria o `Postal\Client` no container, lendo `POSTAL_API_KEY`, e o transporte de e-mail `postal`.

```php
use Postal\Client;

Route::post('/convites', function (Client $postal) {
    $postal->events->send(['name' => 'invite.sent', 'contact' => request('email')]);
});
```

### Mandar os e-mails do Laravel pelo Postal

1. Defina a chave no `.env`:

   ```dotenv
   MAIL_MAILER=postal
   MAIL_FROM_ADDRESS=voce@seudominio.com.br
   MAIL_FROM_NAME="Minha empresa"
   POSTAL_API_KEY=ps_xxx
   ```

2. Em `config/mail.php`, dentro de `mailers`, acrescente:

   ```php
   'postal' => [
       'transport' => 'postal',
       // 'key' => env('POSTAL_API_KEY'), // opcional: uma chave só deste mailer
   ],
   ```

Pronto: `Mail::to(...)->send(...)`, notificações por e-mail e `Mailable`s passam a sair pela API do Postal. O domínio de `MAIL_FROM_ADDRESS` precisa estar verificado. Vão junto os anexos, as imagens embutidas, `Cc`, `Bcc`, `Reply-To`, os cabeçalhos extras e as etiquetas (`tag()` e `metadata()` do `Mailable` viram etiquetas do envio). O ID do e-mail no Postal fica em `$sentMessage->getMessageId()`.

O transporte usa `symfony/mailer`, que já vem com o Laravel. Ele é só uma sugestão (`suggest`) do pacote, para que quem não usa Laravel não precise instalá-lo.

Para publicar o arquivo de configuração (opcional):

```bash
php artisan vendor:publish --tag=postal-config
```

## Testes

```bash
composer install
vendor/bin/phpunit
```

Os testes usam um transporte HTTP falso e, para o `CurlTransport`, um servidor local (`php -S`). Para trocar o transporte HTTP no seu código, passe uma classe que implemente `Postal\Http\Transport` na opção `transport` (o método `send` recebe o `timeout` e o `connect_timeout`, nessa ordem, depois do corpo).

## Licença

MIT
