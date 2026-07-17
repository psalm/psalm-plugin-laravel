<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * `#[Unguarded]` should empty the default `['*']` denylist (guard nothing), exercising the Unguarded branch
 * of `GuardsAttributes::initializeGuardsAttributes()`, which the warm-up replay invokes — distinct from the
 * `$guarded = false` idiom that {@see UnguardedModel} covers.
 * Laravel 13.0+ only; the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Unguarded]
final class UnguardedAttributeModel extends Model {}
