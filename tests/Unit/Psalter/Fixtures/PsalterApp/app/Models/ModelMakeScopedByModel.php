<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * Its global scope comes from the `#[ScopedBy]` attribute, with no boot method of its own.
 */
#[ScopedBy(ModelMakeTenantScope::class)]
class ModelMakeScopedByModel extends Model {}
