--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

/**
 * `$collection->groupBy->field` and `->keyBy->field` type the outer key like the direct `groupBy('field')` form:
 * Laravel casts bool to int and an int-backed enum to its value, so those keys are `int`. A `string` value is not a
 * provable `string` key (PHP casts numeric-string array keys to int), and unknown values stay `array-key`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2341
 */

enum HoGroupIntEnum: int
{
    case One = 1;
    case Two = 2;
}

enum HoGroupStringEnum: string
{
    case Draft = 'draft';
    case Live = 'live';
}

final class HoGroupItem
{
    public int $count = 0;
    public bool $active = true;
    public string $name = '';
    public ?int $nullable = null;
    public HoGroupIntEnum $level = HoGroupIntEnum::One;
    public HoGroupStringEnum $status = HoGroupStringEnum::Draft;
    public mixed $anything = null;

    public function level(): HoGroupIntEnum
    {
        return HoGroupIntEnum::One;
    }
}

/** @param Collection<int, HoGroupItem> $items */
function test_group_by_property(Collection $items): void
{
    $_bool = $items->groupBy->active;
    /** @psalm-check-type-exact $_bool = Collection<int, Collection<int, HoGroupItem>> */

    $_int = $items->groupBy->count;
    /** @psalm-check-type-exact $_int = Collection<int, Collection<int, HoGroupItem>> */

    $_intEnum = $items->groupBy->level;
    /** @psalm-check-type-exact $_intEnum = Collection<int, Collection<int, HoGroupItem>> */

    $_method = $items->groupBy->level();
    /** @psalm-check-type-exact $_method = Collection<int, Collection<int, HoGroupItem>> */

    $_string = $items->groupBy->name;
    /** @psalm-check-type-exact $_string = Collection<array-key, Collection<int, HoGroupItem>> */

    $_stringEnum = $items->groupBy->status;
    /** @psalm-check-type-exact $_stringEnum = Collection<array-key, Collection<int, HoGroupItem>> */

    $_nullable = $items->groupBy->nullable;
    /** @psalm-check-type-exact $_nullable = Collection<array-key, Collection<int, HoGroupItem>> */

    $_mixed = $items->groupBy->anything;
    /** @psalm-check-type-exact $_mixed = Collection<array-key, Collection<int, HoGroupItem>> */
}

/** @param Collection<int, HoGroupItem> $items */
function test_key_by_property(Collection $items): void
{
    $_bool = $items->keyBy->active;
    /** @psalm-check-type-exact $_bool = Collection<int, HoGroupItem> */

    $_int = $items->keyBy->count;
    /** @psalm-check-type-exact $_int = Collection<int, HoGroupItem> */

    $_intEnum = $items->keyBy->level;
    /** @psalm-check-type-exact $_intEnum = Collection<int, HoGroupItem> */

    $_string = $items->keyBy->name;
    /** @psalm-check-type-exact $_string = Collection<array-key, HoGroupItem> */

    $_mixed = $items->keyBy->anything;
    /** @psalm-check-type-exact $_mixed = Collection<array-key, HoGroupItem> */
}
--EXPECTF--
