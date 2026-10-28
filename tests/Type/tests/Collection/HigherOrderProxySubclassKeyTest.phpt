--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.31.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;

class KeyedSubclassItem extends Model {}

/** @extends EloquentCollection<int, KeyedSubclassItem> */
final class KeyedSubclassItems extends EloquentCollection {}

/** @param Enumerable<int, mixed> $_items */
function takesIntKeyedEnumerable(Enumerable $_items): void {}

/**
 * Laravel 13.31+ annotates the vendor proxy `<TMethod, TValue, TCollection>`, so a non-generic subclass
 * receiver reaches the fallback with its TCollection and the key resolves from the subclass's
 * `@extends EloquentCollection<int, ...>`. Before 13.31 the vendor trait names no TCollection: `array-key`.
 */
function subclassKeyComesFromItsExtends(KeyedSubclassItems $items): void
{
    takesIntKeyedEnumerable($items->each->getKey());
}
?>
--EXPECTF--
