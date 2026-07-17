<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns\EnablesTimestampsInInitializer;

/**
 * The only fixture whose answer depends on WHERE `initializeHasTimestamps` runs in the walk, rather than on
 * what it computes. `$timestamps = false` closes its `=== true` guard, the trait initializer reopens it,
 * and `#[WithoutTimestamps]` is what the guard admits or refuses — so the outcome is decided purely by
 * whether the user initializer ran before the framework one.
 *
 * getMethods() ranks the two differently per PHP version (8.4 puts Model's inherited concern initializers
 * first, 8.5 the concrete class's own), so runtime disagrees with itself across the CI matrix and the
 * consuming test must use the runtime oracle with NO literal — see {@see AppendsOrderModel}, same shape.
 *
 * Guards the framework initializer's dispatch POSITION: invoking `initializeHasTimestamps` anywhere other
 * than at its own rank in the walk — hoisting it to the tail beside `initializeModelAttributes()`, which
 * reads the same `#[Table]`, is the plausible tidy-up — diverges from runtime under PHP 8.4. Every other
 * timestamps fixture accepts that hoist.
 *
 * That guard is conditional on the 8.4-vs-8.5 split existing: were a future PHP to settle on one order, the
 * hoist would stop diverging and this would quietly stop guarding. It degrades to a passing test, never a
 * spurious failure, and the oracle keeps it honest meanwhile — but do not read a green here as proof the
 * position is still pinned.
 *
 * Laravel 13.2+ (`#[WithoutTimestamps]`); the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[WithoutTimestamps]
final class TimestampsInitializerOrderModel extends Model
{
    use EnablesTimestampsInInitializer;

    /** @var bool */
    public $timestamps = false;
}
