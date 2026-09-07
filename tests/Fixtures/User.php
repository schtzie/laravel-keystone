<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Schtzie\Keystone\Traits\HasKeystones;

/**
 * Minimal Eloquent model fixture used across all test suites.
 * Represents any "owner" model (e.g. a User, Team, Application).
 */
final class User extends Model
{
    use HasKeystones;

    protected $table = 'users';

    protected $guarded = [];
}
