<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base carrying `#[WithoutTimestamps]` so {@see TimestampsAttributeInheritedModel} can prove an
 * INHERITED attribute is resolved — through Laravel's own `resolveClassAttribute()` ancestor walk, inside
 * the initializer the replay invokes. Kept separate from
 * {@see AttributeConfiguredBase}, whose consuming test gates on the 13.0 `#[Hidden]`: a 13.2 attribute
 * there would outrank its gate.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[WithoutTimestamps]
abstract class TimestampsAttributeInheritedBase extends Model {}
