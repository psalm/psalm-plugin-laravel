<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * `#[Table(timestamps: true)]` on an otherwise-default model: the only fixture that reaches
 * `initializeHasTimestamps()`'s `$timestamps = $table->timestamps` assignment with `true` on the
 * right-hand side. Every other
 * `timestamps: true` fixture short-circuits before it — {@see TimestampsAttributePrecedenceModel} on
 * `#[WithoutTimestamps]`, {@see AttributeOverriddenByPropertyModel} on the `!== true` guard.
 *
 * Without it, a registry that recorded `false` for every attribute-configured model would pass the rest of
 * the timestamps cases while inverting #1276: a `timestamps: true` model reporting false where runtime
 * says true.
 *
 * Laravel 13.0+ only; the consuming test shares the 13.2 `#[WithoutTimestamps]` gate.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Table(name: 'table_timestamps_enabled_models', timestamps: true)]
final class TableTimestampsEnabledModel extends Model {}
