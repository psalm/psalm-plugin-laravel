<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * `#[WithoutTimestamps]` alone — runtime `usesTimestamps()` is false. The archetype of #1276: warm-up
 * used to record true here, because `newInstanceWithoutConstructor()` skips `initializeHasTimestamps()`
 * and no mirror replaced it.
 *
 * `#[WithoutTimestamps]` exists from Laravel 13.2, so the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[WithoutTimestamps]
final class WithoutTimestampsAttributeModel extends Model {}
