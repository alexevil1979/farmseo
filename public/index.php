<?php

declare(strict_types=1);

/**
 * Front controller — /api/v1/* and /internal/*.
 */

use Farm\Db\Connection;
use Farm\Http\Application;
use Farm\Http\Request;
use Farm\Services\ServiceFactory;

$root = dirname(__DIR__);

/** @var array $config */
$config = require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

Connection::reset();
$db = Connection::getInstance($config['db']);
$factory = new ServiceFactory($db, $config);
$app = new Application($factory);

$request = Request::fromGlobals();
$response = $app->handle($request);
$response->send();
