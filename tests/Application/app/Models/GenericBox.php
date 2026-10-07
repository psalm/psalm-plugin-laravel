<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Generic model: a relation declaring `MorphTo<GenericBox<static>, self>` nests a context-dependent
 * type in the related slot (#1753).
 *
 * @template TOwner of object
 */
class GenericBox extends Model {}
