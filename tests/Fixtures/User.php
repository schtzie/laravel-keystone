<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Tests\Fixtures;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Schtzie\Keystone\Traits\HasKeystones;

/**
 * Minimal Eloquent model fixture used across all test suites.
 * Represents any "owner" model (e.g. a User, Team, Application).
 * Implements Authenticatable so actingAs() works in REST route tests.
 */
final class User extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasKeystones;

    protected $table = 'users';

    protected $guarded = [];
}
