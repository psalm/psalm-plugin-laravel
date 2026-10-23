<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns\ForcesIntKeyType;

/**
 * The only fixture that can observe WHERE the `initializeModelAttributes()` phase runs relative to the walk.
 *
 * `#[Table(keyType:)]` is applied behind Laravel's `$this->keyType === 'int'` guard, and the trait initializer
 * writes exactly that value — so the two orders disagree:
 *  - phase LAST (runtime, and what prepare() does): the initializer sets `int`, the guard then admits the
 *    attribute, and `getKeyType()` is `string`.
 *  - phase HOISTED before the walk: the attribute applies to the pristine `int` default, then the initializer
 *    overwrites it, and `getKeyType()` is `int`.
 *
 * The `$table` force-branch is the other way to catch the hoist. The `keyType` guard has no force branch
 * and is byte-identical from 13.3 to 13.38, so this discriminates across the whole `^13.20` range.
 *
 * Laravel 13.0+ (`#[Table]`); the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelInstancePreparerTest
 */
#[Table(name: 'key_type_order_models', keyType: 'string')]
final class KeyTypeInitializerOrderModel extends \Illuminate\Database\Eloquent\Model
{
    use ForcesIntKeyType;
}
