<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;

/**
 * `#[WithoutIncrementing]` — the other, separately-gated way `initializeModelAttributes()` reaches
 * `$incrementing`. It takes precedence over `#[Table(incrementing:)]` rather than merging with it, and unlike
 * the `#[Table]` sub-overrides it is not conditioned on the property still holding its default.
 *
 * Key name and type stay at their defaults here: this fixture isolates the incrementing flag.
 *
 * Laravel 13.2+ — later than the 13.0 `#[Table]` it takes precedence over, so the consuming test gates on
 * THIS attribute rather than that one. Moot under the `^13.3` floor; the gate is what keeps it moot.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[WithoutIncrementing]
final class WithoutIncrementingAttributeModel extends Model {}
