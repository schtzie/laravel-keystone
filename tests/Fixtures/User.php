<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Schatzie\Keystone\Traits\HasApiKeys;

/**
 * Minimal Eloquent model fixture used across all test suites.
 * Represents any "owner" model (e.g. a User, Team, Application).
 */
final class User extends Model
{
    use HasApiKeys;

    protected $table = 'users';

    protected $guarded = [];
}
