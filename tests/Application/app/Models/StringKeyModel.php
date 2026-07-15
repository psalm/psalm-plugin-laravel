<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Model archetype configured with a string key without a UUID/ULID trait. */
final class StringKeyModel extends Model
{
    protected $keyType = 'string';
}
