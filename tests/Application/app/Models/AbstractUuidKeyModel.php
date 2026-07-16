<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base using HasUuids — regression fixture for an abstract-typed receiver. HasUuids
 * overrides the getKeyType() METHOD and declares no $keyType property, so the abstract warm-up
 * path (which reads only declared-property defaults) can't see the override.
 */
abstract class AbstractUuidKeyModel extends Model
{
    use HasUuids;
}
