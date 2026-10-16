--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('12.26.4');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

enum KeyByEnumBelowStatus: string
{
    case Active = 'active';
}

final class KeyByEnumBelowItem
{
    public KeyByEnumBelowStatus $status = KeyByEnumBelowStatus::Active;
}

/**
 * Before 12.26.4 keyBy() casts every object key with `(string)`, so any enum throws; only the
 * enum's scalar (`->value`) is a valid key.
 *
 * @param Collection<int, KeyByEnumBelowItem> $items
 */
function enum_keys_before_12264(Collection $items): void
{
    $items->keyBy(fn (KeyByEnumBelowItem $item): KeyByEnumBelowStatus => $item->status);

    $_untyped = $items->keyBy(function ($item) {
        /** @psalm-check-type-exact $item = KeyByEnumBelowItem */
        return $item->status->value;
    });
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::keyBy expects array<array-key, mixed>|callable[impure](KeyByEnumBelowItem, int):array-key|string, but Closure[read-props](KeyByEnumBelowItem):KeyByEnumBelowStatus provided
