<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * Inherits `#[WithoutTimestamps]` from {@see TimestampsAttributeInheritedBase} while declaring its own
 * `#[Table(timestamps: true)]`. Runtime resolves the parent's attribute first and disables timestamps, so
 * the child's own argument loses.
 *
 * Pins two things nothing else does. The mirror must reach `#[WithoutTimestamps]` through the ANCESTOR
 * walk — a non-recursive `$reflection->getAttributes()` (the idiom `applyTableAttribute()` correctly uses
 * for its own `$declaresTable` check) would miss it here and fall through to the child's `#[Table]`,
 * recording true. And precedence is decided ACROSS the hierarchy, not per class, which
 * {@see TimestampsAttributePrecedenceModel} cannot show with both attributes on one class.
 *
 * Laravel 13.2+ (`#[WithoutTimestamps]`); the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Table(name: 'timestamps_inherited_models', timestamps: true)]
final class TimestampsAttributeInheritedModel extends TimestampsAttributeInheritedBase {}
