<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    $environment = $context['APP_ENV'] ?? null;

    if (!is_string($environment)) {
        throw new RuntimeException('The APP_ENV environment variable must be a string.');
    }

    $debug = filter_var($context['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

    return new Kernel($environment, $debug);
};
