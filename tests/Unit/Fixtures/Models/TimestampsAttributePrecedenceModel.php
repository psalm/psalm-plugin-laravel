<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Both forms, disagreeing: `#[WithoutTimestamps]` wins over `#[Table(timestamps: true)]` because
 * `initializeHasTimestamps()` checks it first and returns. Pins the mirror's branch ORDER — swap the two
 * and this model records true while runtime says false.
 *
 * Laravel 13.2+ (`#[WithoutTimestamps]`); the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[WithoutTimestamps]
#[Table(name: 'timestamps_precedence_models', timestamps: true)]
final class TimestampsAttributePrecedenceModel extends Model {}
