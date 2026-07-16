<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * Inherits `#[WithoutTimestamps]` from {@see TimestampsAttributeInheritedBase} while declaring its own
 * `#[Table(timestamps: true)]`. Runtime resolves the parent's attribute first and disables timestamps, so
 * the child's own argument loses.
 *
 * Pins that precedence is decided ACROSS the hierarchy, not per class — which
 * {@see TimestampsAttributePrecedenceModel} cannot show, carrying both attributes on one class.
 *
 * The ancestor walk itself is Laravel's: the replay invokes the real `initializeHasTimestamps()`, whose
 * `resolveClassAttribute()` climbs the parents. So what is at stake here is the registry's read of the
 * result, not a hand-written walk — and a replay that resolved `#[WithoutTimestamps]` non-recursively would
 * still be caught, falling through to the child's `#[Table]` and recording true.
 *
 * Laravel 13.2+ (`#[WithoutTimestamps]`); the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Table(name: 'timestamps_inherited_models', timestamps: true)]
final class TimestampsAttributeInheritedModel extends TimestampsAttributeInheritedBase {}
