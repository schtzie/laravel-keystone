<?php

declare(strict_types=1);

use Schtzie\Keystone\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

pest()->beforeEach(function (): void {
    config(['keystone.cache.enabled' => true]);
});
