<?php

declare(strict_types=1);

namespace Postal\Tests;

use Illuminate\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Foundation\Application;
use Illuminate\Mail\MailManager;
use PHPUnit\Framework\TestCase;
use Postal\Client;
use Postal\Laravel\PostalServiceProvider;
use Postal\Laravel\PostalTransport;

final class ServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(Application::class)) {
            $this->markTestSkipped('laravel/framework não está instalado.');
        }
    }

    /**
     * @param  array<string, mixed>  $postal
     * @param  array<string, mixed>  $mailers
     */
    private function app(array $postal = [], array $mailers = ['postal' => ['transport' => 'postal']]): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository(['postal' => $postal, 'mail' => ['default' => 'postal', 'mailers' => $mailers]]));
        $app->instance('view', $this->createStub(ViewFactory::class));
        $app->singleton('mail.manager', fn (Application $app): MailManager => new MailManager($app));
        $app->register(PostalServiceProvider::class);
        $app->boot();

        return $app;
    }

    public function test_registers_the_client_from_the_config(): void
    {
        $app = $this->app(['key' => 'ps_from_config', 'base_url' => 'https://api.exemplo.test']);
        $client = $app->make(Client::class);

        $this->assertSame($client, $app->make('postal'));
        $this->assertSame($client, $app->make(Client::class), 'é um singleton');
    }

    public function test_merges_the_package_config_defaults(): void
    {
        $app = $this->app(['key' => 'ps_x']);

        $this->assertSame('https://api.postal.so', $app['config']->get('postal.base_url'));
        $this->assertSame(2, $app['config']->get('postal.max_retries'));
        $this->assertSame(30.0, $app['config']->get('postal.timeout'));
        $this->assertSame(5.0, $app['config']->get('postal.connect_timeout'));
    }

    public function test_passes_the_timeout_options_from_the_config_to_the_client(): void
    {
        $app = $this->app(['key' => 'ps_x', 'max_retries' => '1', 'timeout' => '10', 'connect_timeout' => '2.5']);
        $client = $app->make(Client::class);
        $properties = [];

        foreach (['maxRetries', 'timeout', 'connectTimeout'] as $name) {
            $properties[$name] = (new \ReflectionProperty($client, $name))->getValue($client);
        }

        $this->assertSame(['maxRetries' => 1, 'timeout' => 10.0, 'connectTimeout' => 2.5], $properties);
    }

    public function test_adds_the_postal_mail_transport(): void
    {
        $app = $this->app(['key' => 'ps_x']);
        $transport = $app->make('mail.manager')->mailer('postal')->getSymfonyTransport();

        $this->assertInstanceOf(PostalTransport::class, $transport);
    }

    public function test_a_mailer_can_have_its_own_key(): void
    {
        $app = $this->app(['key' => 'ps_global'], ['postal' => ['transport' => 'postal', 'key' => 'ps_do_mailer']]);
        $transport = $app->make('mail.manager')->mailer('postal')->getSymfonyTransport();
        $client = (new \ReflectionProperty($transport, 'client'))->getValue($transport);

        $this->assertNotSame($app->make(Client::class), $client);
        $this->assertInstanceOf(Client::class, $client);
    }

    public function test_the_composer_manifest_declares_the_provider_for_auto_discovery(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

        $this->assertSame([PostalServiceProvider::class], $manifest['extra']['laravel']['providers']);
        $this->assertArrayHasKey('symfony/mailer', $manifest['suggest']);
        $this->assertArrayNotHasKey('symfony/mailer', $manifest['require']);
    }
}
