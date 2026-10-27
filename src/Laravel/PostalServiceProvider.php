<?php

declare(strict_types=1);

namespace Postal\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use Postal\Client;

/**
 * Liga o Postal ao Laravel (auto-discovery): registra o {@see Client} no container, publica
 * `config/postal.php` e cria o transporte de e-mail `postal`.
 *
 * Para mandar os e-mails do Laravel pelo Postal, defina `MAIL_MAILER=postal` e acrescente em
 * `config/mail.php`, dentro de `mailers`:
 *
 * ```php
 * 'postal' => ['transport' => 'postal'],
 * ```
 */
class PostalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/postal.php', 'postal');

        $this->app->singleton(Client::class, fn (Application $app): Client => self::makeClient($app['config']->get('postal', [])));
        $this->app->alias(Client::class, 'postal');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/postal.php' => $this->app->configPath('postal.php')], 'postal-config');
        }

        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('postal', function (array $config): PostalTransport {
                // Um mailer pode ter a própria chave: 'postal' => ['transport' => 'postal', 'key' => env('...')].
                $client = isset($config['key'])
                    ? self::makeClient([...$this->app['config']->get('postal', []), 'key' => $config['key']])
                    : $this->app->make(Client::class);

                return new PostalTransport($client);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function makeClient(array $config): Client
    {
        return new Client($config['key'] ?? null, array_filter([
            'base_url' => $config['base_url'] ?? null,
            'max_retries' => isset($config['max_retries']) ? (int) $config['max_retries'] : null,
            'timeout' => isset($config['timeout']) ? (float) $config['timeout'] : null,
            'connect_timeout' => isset($config['connect_timeout']) ? (float) $config['connect_timeout'] : null,
            'user_agent' => 'laravel',
        ], static fn (mixed $value): bool => $value !== null));
    }
}
