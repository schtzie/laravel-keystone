<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Schtzie\Keystone\Facades\KeystoneAnalytics;
use Schtzie\Keystone\Models\KeystoneAccessLog;
use Schtzie\Keystone\Tests\Fixtures\User;

beforeEach(function (): void {
    config([
        'keystone.access_log.enabled' => true,
        'keystone.access_log.driver' => 'database',
    ]);
});

it('returns daily usage for a specific key', function (): void {
    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('My App')['model'];

    KeystoneAccessLog::insert([
        [
            'keystone_id' => $key->id,
            'event' => 'authenticated',
            'status_code' => 200,
            'created_at' => Carbon::now()->subDays(2),
            'method' => 'GET',
            'path' => '/',
        ],
        [
            'keystone_id' => $key->id,
            'event' => 'authenticated',
            'status_code' => 200,
            'created_at' => Carbon::now()->subDays(2),
            'method' => 'GET',
            'path' => '/',
        ],
        [
            'keystone_id' => $key->id,
            'event' => 'rejected_invalid',
            'status_code' => 401,
            'created_at' => Carbon::now()->subDays(1),
            'method' => 'GET',
            'path' => '/',
        ]
    ]);

    $usage = KeystoneAnalytics::dailyUsage($key, 7);

    expect($usage)->toHaveCount(2);

    $day1 = $usage->firstWhere('date', Carbon::now()->subDays(2)->format('Y-m-d'));
    expect($day1->event)->toBe('authenticated')
        ->and($day1->count)->toBe(2);

    $day2 = $usage->firstWhere('date', Carbon::now()->subDays(1)->format('Y-m-d'));
    expect($day2->event)->toBe('rejected_invalid')
        ->and($day2->count)->toBe(1);
});

it('returns top keys by authenticated requests', function (): void {
    $user = User::create(['name' => 'Test']);
    
    $key1 = $user->createKeystone('App 1')['model'];
    $key2 = $user->createKeystone('App 2')['model'];
    
    $logs = [];
    
    // key1: 3 auth, 1 rejected
    for ($i = 0; $i < 3; $i++) {
        $logs[] = ['keystone_id' => $key1->id, 'event' => 'authenticated', 'status_code' => 200, 'created_at' => now(), 'method' => 'GET', 'path' => '/'];
    }
    $logs[] = ['keystone_id' => $key1->id, 'event' => 'rejected_invalid', 'status_code' => 401, 'created_at' => now(), 'method' => 'GET', 'path' => '/'];
    
    // key2: 5 auth
    for ($i = 0; $i < 5; $i++) {
        $logs[] = ['keystone_id' => $key2->id, 'event' => 'authenticated', 'status_code' => 200, 'created_at' => now(), 'method' => 'GET', 'path' => '/'];
    }
    
    KeystoneAccessLog::insert($logs);

    $top = KeystoneAnalytics::topKeys(10);
    
    expect($top)->toHaveCount(2);
    expect($top[0]->keystone_id)->toBe($key2->id)
        ->and($top[0]->requests)->toBe(5);
    expect($top[1]->keystone_id)->toBe($key1->id)
        ->and($top[1]->requests)->toBe(3);
});

it('returns owner usage across multiple keys', function (): void {
    $user1 = User::create(['name' => 'Test 1']);
    $user2 = User::create(['name' => 'Test 2']);
    
    $key1 = $user1->createKeystone('App 1')['model'];
    $key2 = $user1->createKeystone('App 2')['model'];
    $key3 = $user2->createKeystone('App 3')['model'];
    
    $date = Carbon::now()->subDays(1);
    
    KeystoneAccessLog::insert([
        // user 1
        ['keystoneable_type' => User::class, 'keystoneable_id' => $user1->id, 'keystone_id' => $key1->id, 'event' => 'authenticated', 'status_code' => 200, 'created_at' => $date, 'method' => 'GET', 'path' => '/'],
        ['keystoneable_type' => User::class, 'keystoneable_id' => $user1->id, 'keystone_id' => $key2->id, 'event' => 'authenticated', 'status_code' => 200, 'created_at' => $date, 'method' => 'GET', 'path' => '/'],
        
        // user 2
        ['keystoneable_type' => User::class, 'keystoneable_id' => $user2->id, 'keystone_id' => $key3->id, 'event' => 'authenticated', 'status_code' => 200, 'created_at' => $date, 'method' => 'GET', 'path' => '/'],
    ]);

    $usage = KeystoneAnalytics::ownerUsage($user1, 7);
    
    expect($usage)->toHaveCount(1);
    expect($usage[0]->date)->toBe($date->format('Y-m-d'))
        ->and($usage[0]->count)->toBe(2);
});

it('returns a rejection summary for a key', function (): void {
    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('App')['model'];
    
    KeystoneAccessLog::insert([
        ['keystone_id' => $key->id, 'event' => 'rejected_invalid', 'status_code' => 401, 'created_at' => now(), 'method' => 'GET', 'path' => '/'],
        ['keystone_id' => $key->id, 'event' => 'rejected_invalid', 'status_code' => 401, 'created_at' => now(), 'method' => 'GET', 'path' => '/'],
        ['keystone_id' => $key->id, 'event' => 'rejected_scope', 'status_code' => 403, 'created_at' => now(), 'method' => 'GET', 'path' => '/'],
        ['keystone_id' => $key->id, 'event' => 'authenticated', 'status_code' => 200, 'created_at' => now(), 'method' => 'GET', 'path' => '/'],
    ]);

    $summary = KeystoneAnalytics::rejectionSummary($key, 7);
    
    expect($summary)->toHaveCount(2);
    expect($summary[0]->event)->toBe('rejected_invalid')
        ->and($summary[0]->count)->toBe(2);
    expect($summary[1]->event)->toBe('rejected_scope')
        ->and($summary[1]->count)->toBe(1);
});

it('returns total authenticated requests', function (): void {
    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('App')['model'];
    
    KeystoneAccessLog::insert([
        ['keystone_id' => $key->id, 'event' => 'authenticated', 'status_code' => 200, 'created_at' => now(), 'method' => 'GET', 'path' => '/'],
        ['keystone_id' => $key->id, 'event' => 'authenticated', 'status_code' => 200, 'created_at' => now(), 'method' => 'GET', 'path' => '/'],
        ['keystone_id' => $key->id, 'event' => 'rejected_invalid', 'status_code' => 401, 'created_at' => now(), 'method' => 'GET', 'path' => '/'],
    ]);

    $total = KeystoneAnalytics::totalRequests(7);
    
    expect($total)->toBe(2);
});
