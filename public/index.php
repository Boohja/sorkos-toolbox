<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/f3/base.php';
require dirname(__DIR__) . '/app/Core/ToolRegistry.php';
require dirname(__DIR__) . '/app/Core/Application.php';

$app = new App\Core\Application(
    Base::instance(),
    dirname(__DIR__)
);

$app->run();
