<?php

declare(strict_types=1);

$preloadFiles = glob(dirname(__DIR__).'/var/cache/prod/*.preload.php');

if (false === $preloadFiles) {
    $preloadFiles = [];
}

foreach ($preloadFiles as $file) {
    require $file;
}
