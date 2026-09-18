<?php

declare(strict_types=1);

use App\Tests\Integration\ConcurrencyWorker;

require dirname(__DIR__).'/vendor/autoload.php';

exit((new ConcurrencyWorker())->run($argv));
