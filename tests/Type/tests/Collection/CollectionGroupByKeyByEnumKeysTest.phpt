--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.27.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

enum EnumKeysSuit
{
    case Hearts;
    case Spades;
}

enum EnumKeysStatus: string
{
    case Active = 'active';
}

final class EnumKeysItem
{
    public EnumKeysSuit $suit = EnumKeysSuit::Hearts;

    public EnumKeysStatus $status = EnumKeysStatus::Active;
}

/**
 * Laravel 12.27.0 resolves a pure UnitEnum group key through enum_value() (12.26.4 for keyBy()),
 * so both accept it from stubs/12.27.0/; before that a pure enum fatals.
 *
 * @param Collection<int, EnumKeysItem> $items
 */
function pure_unit_enum_keys(Collection $items): void
{
    $_grouped = $items->groupBy(fn (EnumKeysItem $item): EnumKeysSuit => $item->suit);
    /** @psalm-check-type-exact $_grouped = Collection<array-key, Collection<int, EnumKeysItem>&static>&static */

    $_keyed = $items->keyBy(fn (EnumKeysItem $item): EnumKeysSuit => $item->suit);
    /** @psalm-check-type-exact $_keyed = Collection<array-key, EnumKeysItem>&static */
}

/** @param Collection<int, EnumKeysItem> $items */
function backed_enum_keys(Collection $items): void
{
    $_grouped = $items->groupBy(fn (EnumKeysItem $item): EnumKeysStatus => $item->status);
    /** @psalm-check-type-exact $_grouped = Collection<array-key, Collection<int, EnumKeysItem>&static>&static */
}

/** @param Collection<int, EnumKeysItem> $items */
function untyped_closures_still_bind_the_item(Collection $items): void
{
    $items->groupBy(function ($item) {
        /** @psalm-check-type-exact $item = EnumKeysItem */
        return $item->suit;
    });

    $items->keyBy(function ($item) {
        /** @psalm-check-type-exact $item = EnumKeysItem */
        return $item->suit;
    });
}
?>
--EXPECTF--
