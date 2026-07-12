<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Archetype: a model using Laravel's `$guarded = false` ("guard nothing") idiom, where
 * `getGuarded()` returns a bool, not an array (laravel/passport's models do this). Guards the
 * registry warm-up against TypeError-ing on the non-array return. See #591.
 */
class UnguardedModel extends Model
{
    /** @var false */
    protected $guarded = false;
}
