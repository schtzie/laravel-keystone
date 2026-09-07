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

it('does not send rate limit headers when rate limiting is disabled', function () {
    config(['keystone.rate_limit' => null]);

    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('Unlimited Key', [], null, ['rate_limit' => null]);

    $response = getProtectedRateLimit($this, $key);
    $response->assertOk();
    $this->assertFalse($response->headers->has('X-Keystone-RateLimit-Limit'));
    $this->assertFalse($response->headers->has('X-Keystone-RateLimit-Remaining'));
});

it('prioritizes per-key rate limit over global config rate limit', function () {
    // Global config set to 10
    config(['keystone.rate_limit' => 10]);

    $user = User::create(['name' => 'Test']);
    // Key specifically configured to 1
    $key = $user->createKeystone('Strict Key', [], null, ['rate_limit' => 1]);

    RateLimiter::clear('keystone:rate_limit:'.$key['model']->id);

    // Request 1 succeeds and shows per-key limit of 1 (not 10)
    $response1 = getProtectedRateLimit($this, $key);
    $response1->assertOk();
    $response1->assertHeader('X-Keystone-RateLimit-Limit', '1');
    $response1->assertHeader('X-Keystone-RateLimit-Remaining', '0');

    // Request 2 throttled despite global config allowing 10
    $response2 = getProtectedRateLimit($this, $key);
    $response2->assertStatus(429);
    $response2->assertHeader('X-Keystone-RateLimit-Limit', '1');
});

it('handles zero or negative rate limit values by bypassing rate limiting without errors', function () {
    config(['keystone.rate_limit' => null]);

    $user = User::create(['name' => 'Test']);
    $zeroKey = $user->createKeystone('Zero Key', [], null, ['rate_limit' => 0]);
    $negativeKey = $user->createKeystone('Negative Key', [], null, ['rate_limit' => -5]);

    // Zero limit key -> no throttling, no headers
    $resZero = getProtectedRateLimit($this, $zeroKey);
    $resZero->assertOk();
    $this->assertFalse($resZero->headers->has('X-Keystone-RateLimit-Limit'));

    // Negative limit key -> no throttling, no headers
    $resNeg = getProtectedRateLimit($this, $negativeKey);
    $resNeg->assertOk();
    $this->assertFalse($resNeg->headers->has('X-Keystone-RateLimit-Limit'));
});

it('isolates rate limits between different keys', function () {
    $user = User::create(['name' => 'Test']);
    $keyA = $user->createKeystone('Key A', [], null, ['rate_limit' => 1]);
    $keyB = $user->createKeystone('Key B', [], null, ['rate_limit' => 1]);

    RateLimiter::clear('keystone:rate_limit:'.$keyA['model']->id);
    RateLimiter::clear('keystone:rate_limit:'.$keyB['model']->id);

    // Key A uses its 1 allowed request and is now throttled
    getProtectedRateLimit($this, $keyA)->assertOk();
    getProtectedRateLimit($this, $keyA)->assertStatus(429);

    // Key B is independent and still succeeds
    getProtectedRateLimit($this, $keyB)->assertOk();
});

it('handles string numeric rate limit values correctly', function () {
    $user = User::create(['name' => 'Test']);
    // String "3" should be cast and enforced as 3
    $key = $user->createKeystone('String Limit Key', [], null, ['rate_limit' => '3']);

    RateLimiter::clear('keystone:rate_limit:'.$key['model']->id);

    $response = getProtectedRateLimit($this, $key);
    $response->assertOk();
    $response->assertHeader('X-Keystone-RateLimit-Limit', '3');
    $response->assertHeader('X-Keystone-RateLimit-Remaining', '2');
});