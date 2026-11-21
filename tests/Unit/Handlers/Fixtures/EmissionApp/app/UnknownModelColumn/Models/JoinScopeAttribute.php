<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/** Laravel reads scope attributes with `IS_INSTANCEOF`, so a subclass registers scopes too. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class JoinScopeAttribute extends ScopedBy {}
