<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base carrying `#[WithoutTimestamps]` so {@see TimestampsAttributeInheritedModel} can prove the
 * mirror resolves it through `classAttribute()`'s ancestor walk. Kept separate from
 * {@see AttributeConfiguredBase}, whose consuming test gates on the 13.0 `#[Hidden]`: a 13.2 attribute
 * there would outrank its gate.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[WithoutTimestamps]
abstract class TimestampsAttributeInheritedBase extends Model {}
