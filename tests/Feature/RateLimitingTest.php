<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Route::middleware('api.key')->get('/protected', fn () => 'ok');
});

function getProtectedRateLimit(object $testCase, array $key) {
    $client = $key['client'];
    $secret = $key['secret'];
    $signature = hash_hmac('sha256', $client, $secret);

    return $testCase->withHeaders([
        'X-Client-Id' => $client,
        'X-API-Signature' => $signature,
    ])->get('/protected');
}

it('enforces rate limits per key', function () {
    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('Test Key', [], null, ['rate_limit' => 2]);
    
    RateLimiter::clear('keystone:rate_limit:'.$key['model']->id);

    // Request 1
    $response1 = getProtectedRateLimit($this, $key);
    $response1->assertOk();
    $response1->assertHeader('X-Keystone-RateLimit-Limit', '2');
    $response1->assertHeader('X-Keystone-RateLimit-Remaining', '1');

    // Request 2
    $response2 = getProtectedRateLimit($this, $key);
    $response2->assertOk();
    $response2->assertHeader('X-Keystone-RateLimit-Remaining', '0');

    // Request 3 (exceeds)
    $response3 = getProtectedRateLimit($this, $key);
    $response3->assertStatus(429);
    $response3->assertJson(['message' => 'Too many requests.']);
    $response3->assertHeader('X-Keystone-RateLimit-Limit', '2');
    $response3->assertHeader('X-Keystone-RateLimit-Remaining', '0');
    $this->assertTrue($response3->headers->has('Retry-After'));
    $this->assertTrue($response3->headers->has('X-Keystone-RateLimit-Reset'));
});

it('falls back to global rate limit in config', function () {
    config(['keystone.rate_limit' => 1]);

    $user = User::create(['name' => 'Test']);
    // Key has NO specific rate limit
    $key = $user->createKeystone('Test Key');
    
    RateLimiter::clear('keystone:rate_limit:'.$key['model']->id);

    // Request 1
    $response1 = getProtectedRateLimit($this, $key);
    $response1->assertOk();
    $response1->assertHeader('X-Keystone-RateLimit-Limit', '1');
    $response1->assertHeader('X-Keystone-RateLimit-Remaining', '0');

    // Request 2 (exceeds global limit)
    $response2 = getProtectedRateLimit($this, $key);
    $response2->assertStatus(429);
    $response2->assertHeader('X-Keystone-RateLimit-Limit', '1');
    $response2->assertHeader('X-Keystone-RateLimit-Remaining', '0');
});
