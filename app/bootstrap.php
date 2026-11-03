<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "Autoloader not found. Run: composer install\n");
    exit(1);
}

require $autoload;

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config.php';

return $config;
