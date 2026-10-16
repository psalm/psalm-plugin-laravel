--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\HigherOrderCollectionProxy;

/** @param HigherOrderCollectionProxy<'filter', string, Collection<int, string>> $_proxy */
function takesFilterProxy(HigherOrderCollectionProxy $_proxy): void {}

/** @param Collection<int, string> $items */
function mapProxyIsNotAFilterProxy(Collection $items): void
{
    takesFilterProxy($items->map);
    takesFilterProxy($items->filter);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of takesFilterProxy expects Illuminate\Support\HigherOrderCollectionProxy<'filter', string, Illuminate\Support\Collection<int, string>>, but Illuminate\Support\HigherOrderCollectionProxy<'map', string, Illuminate\Support\Collection<int, string>> provided
