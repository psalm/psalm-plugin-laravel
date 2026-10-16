--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.26.4');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

enum KeyByEnumSuit
{
    case Hearts;
    case Spades;
}

enum KeyByEnumStatus: string
{
    case Active = 'active';
}

final class KeyByEnumItem
{
    public KeyByEnumSuit $suit = KeyByEnumSuit::Hearts;

    public KeyByEnumStatus $status = KeyByEnumStatus::Active;
}

/**
 * Laravel 12.26.4 resolves an enum key through enum_value() in keyBy(), so a pure UnitEnum and a
 * BackedEnum are both accepted from stubs/12.26.4/; before that any enum fatals on `(string)`.
 *
 * @param Collection<int, KeyByEnumItem> $items
 */
function enum_keys(Collection $items): void
{
    $_pure = $items->keyBy(fn (KeyByEnumItem $item): KeyByEnumSuit => $item->suit);
    /** @psalm-check-type-exact $_pure = Collection<array-key, KeyByEnumItem>&static */

    $_backed = $items->keyBy(fn (KeyByEnumItem $item): KeyByEnumStatus => $item->status);
    /** @psalm-check-type-exact $_backed = Collection<array-key, KeyByEnumItem>&static */
}

/** @param Collection<int, KeyByEnumItem> $items */
function untyped_closure_still_binds_the_item(Collection $items): void
{
    $items->keyBy(function ($item) {
        /** @psalm-check-type-exact $item = KeyByEnumItem */
        return $item->suit;
    });
}
?>
--EXPECTF--
