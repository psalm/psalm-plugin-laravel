--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('12.27.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

enum EnumKeysBelowSuit
{
    case Hearts;
}

enum EnumKeysBelowStatus: string
{
    case Active = 'active';
}

final class EnumKeysBelowItem
{
    public EnumKeysBelowSuit $suit = EnumKeysBelowSuit::Hearts;

    public EnumKeysBelowStatus $status = EnumKeysBelowStatus::Active;
}

/**
 * Before 12.27.0 groupBy() maps only BackedEnum (->value), so a pure UnitEnum key reaches
 * array_key_exists() and throws. keyBy() casts every object with `(string)`, so any enum throws
 * up to 12.26.3; keyBy() enum support starts at 12.26.4, so 12.26.4 - 12.26.x keep this
 * conservative rejection (the 12.27.0 stub dir carries both methods).
 *
 * @param Collection<int, EnumKeysBelowItem> $items
 */
function enum_keys_before_1227(Collection $items): void
{
    $_backed = $items->groupBy(fn (EnumKeysBelowItem $item): EnumKeysBelowStatus => $item->status);
    /** @psalm-check-type-exact $_backed = Collection<array-key, Collection<int, EnumKeysBelowItem>&static>&static */

    $items->groupBy(fn (EnumKeysBelowItem $item): EnumKeysBelowSuit => $item->suit);

    $items->groupBy(function ($item) {
        /** @psalm-check-type-exact $item = EnumKeysBelowItem */
        return $item->status;
    });

    $items->keyBy(fn (EnumKeysBelowItem $item): EnumKeysBelowStatus => $item->status);

    $_untyped = $items->keyBy(function ($item) {
        /** @psalm-check-type-exact $item = EnumKeysBelowItem */
        return $item->status->value;
    });
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::groupBy expects array<array-key, mixed>|callable[impure](EnumKeysBelowItem, int):(BackedEnum|Stringable|array-key)|string, but Closure[read-props](EnumKeysBelowItem):EnumKeysBelowSuit provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::keyBy expects array<array-key, mixed>|callable[impure](EnumKeysBelowItem, int):array-key|string, but Closure[read-props](EnumKeysBelowItem):EnumKeysBelowStatus provided
