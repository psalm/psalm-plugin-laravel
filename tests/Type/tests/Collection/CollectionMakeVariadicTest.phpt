--FILE--
<?php declare(strict_types=1);

// make() accepts a trailing ...$args variadic (real since Laravel 13.3.0, laravel/framework
// commit 6ae99c9533; claimed unconditionally in the common stub - harmless on the 12.x floor,
// where PHP silently ignores the excess call-site argument) so subclasses with extended
// constructors can forward extra constructor args through make().

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

Collection::make([1, 2], 'extra');
LazyCollection::make([1, 2], 'extra');

?>
--EXPECTF--
