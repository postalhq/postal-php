<?php

declare(strict_types=1);

/*
 * Loads the package's autoload (composer install or composer dump-autoload).
 *
 * The Laravel transport tests need symfony/mailer and laravel/framework. If the PHPUnit in use
 * does not load them by itself, point POSTAL_EXTRA_AUTOLOAD at the autoload of a project that has them.
 */

$autoload = __DIR__.'/../vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Run \"composer install\" (or \"composer dump-autoload\") before the tests.\n");
    exit(1);
}

require $autoload;

$extra = getenv('POSTAL_EXTRA_AUTOLOAD');

if (is_string($extra) && $extra !== '' && is_file($extra)) {
    require $extra;
}
