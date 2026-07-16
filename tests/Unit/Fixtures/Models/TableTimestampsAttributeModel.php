<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * `#[Table(timestamps: false)]`, the second form `initializeHasTimestamps()` honours. Paired with
 * {@see AttributeConfiguredModel}, whose `#[Table]` carries no `timestamps:` argument and must stay
 * timestamped: together they pin that the mirror reads the argument rather than the attribute's presence.
 *
 * Uses only `#[Table]` (Laravel 13.0+), but its consuming test shares a 13.2 `#[WithoutTimestamps]`
 * gate with the rest of the timestamps cases.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Table(name: 'table_timestamps_models', timestamps: false)]
final class TableTimestampsAttributeModel extends Model {}
