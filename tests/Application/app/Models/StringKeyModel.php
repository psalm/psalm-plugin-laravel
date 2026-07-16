<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plain `$keyType = 'string'` override — the property-only path to a string primary key,
 * distinct from the trait-driven UuidModel/UlidModel archetypes.
 */
class StringKeyModel extends Model
{
    protected $keyType = 'string';
}
