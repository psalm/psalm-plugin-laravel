<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Both forms, disagreeing: `#[WithoutTimestamps]` wins over `#[Table(timestamps: true)]` because
 * `initializeHasTimestamps()` checks it first and returns. The branch order is Laravel's, and the replay
 * invokes it — so what this pins is the registry's read of a precedence it no longer reproduces itself.
 *
 * Laravel 13.2+ (`#[WithoutTimestamps]`); the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[WithoutTimestamps]
#[Table(name: 'timestamps_precedence_models', timestamps: true)]
final class TimestampsAttributePrecedenceModel extends Model {}
