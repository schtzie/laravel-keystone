<?php

declare(strict_types=1);

use Schatzie\Keystone\Tests\TestCase;

pest()->extend(TestCase::class)->in(
    'Feature/ApiKeyTest.php',
    'Feature/CacheTest.php',
    'Feature/SingleDbTenancyTest.php',
    'Feature/MultiDbTenancyTest.php',
);
