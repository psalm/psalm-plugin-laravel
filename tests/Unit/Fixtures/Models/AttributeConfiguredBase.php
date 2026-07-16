<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base carrying `#[Hidden]`/`#[Appends]` so {@see AttributeConfiguredChild} can prove an inherited
 * attribute is resolved. The two now travel different roads: `#[Hidden]` through Laravel's own
 * `resolveClassAttribute()` inside the invoked `initializeHidesAttributes()`, `#[Appends]` through the
 * plugin's `classAttribute()` — the one remaining mirror, and the only caller left. Laravel 13.0+ only.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Hidden('base_hidden')]
#[Appends('base_append')]
abstract class AttributeConfiguredBase extends Model {}
