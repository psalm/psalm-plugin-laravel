<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Attribute;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/**
 * Laravel reads `#[ScopedBy]` with `IS_INSTANCEOF`, so a subclass of the attribute registers a global scope too.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ModelMakeTenantScopedBy extends ScopedBy {}
