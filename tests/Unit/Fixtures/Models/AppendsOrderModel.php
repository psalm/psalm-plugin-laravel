<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns\SetsAppendInInitializer;

/**
 * `#[Appends('attribute_append')]` plus a trait `setAppends(['trait_only'])`. Which survives is PHP-dependent:
 * `getMethods()` ranks the user initializer against `initializeHasAttributes`' `mergeAppends()` differently on
 * 8.4 and 8.5 (see {@see SetsAppendInInitializer}), so runtime `getAppends()` yields both on 8.5 and
 * `['trait_only']` on 8.4. The registry must reproduce whichever the installed PHP produces — running the
 * initializer and the framework initializer's stand-in in the same reflection order Laravel observes — which
 * is why the consuming test reads a constructed model as its oracle instead of pinning a literal.
 *
 * The `#[Appends]` attribute exists from Laravel 13.0, so the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest and ModelInstancePreparerTest
 */
#[Appends('attribute_append')]
final class AppendsOrderModel extends Model
{
    use SetsAppendInInitializer;
}
