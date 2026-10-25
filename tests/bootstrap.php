<?php

declare(strict_types=1);

/*
 * Carrega o autoload do pacote (composer install ou composer dump-autoload).
 *
 * Os testes do transporte do Laravel precisam de symfony/mailer e laravel/framework. Se o PHPUnit usado
 * não os carrega sozinho, aponte POSTAL_EXTRA_AUTOLOAD para o autoload de um projeto que os tenha.
 */

$autoload = __DIR__.'/../vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Rode \"composer install\" (ou \"composer dump-autoload\") antes dos testes.\n");
    exit(1);
}

require $autoload;

$extra = getenv('POSTAL_EXTRA_AUTOLOAD');

if (is_string($extra) && $extra !== '' && is_file($extra)) {
    require $extra;
}
