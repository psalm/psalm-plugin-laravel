<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns\SetsAppendInInitializer;

/**
 * `#[Appends('attribute_append')]` plus a trait `setAppends(['trait_only'])`. Runtime `getAppends()` returns
 * BOTH, because the user initializer runs before `initializeHasAttributes`' `mergeAppends()`. The registry
 * must reproduce that exactly — replaying the initializer before `applyClassAttributeConfig()`.
 *
 * The `#[Appends]` attribute exists from Laravel 13.0, so the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Appends('attribute_append')]
final class AppendsOrderModel extends Model
{
    use SetsAppendInInitializer;
}
