--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.30.1');
--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use Illuminate\Support\Collection;

// insertOrIgnoreReturning joined Eloquent\Builder::$passthru in Laravel 13.30.1 (#1734).
function insert_or_ignore_returning(Mechanic $m): Collection
{
    return $m->workOrders()->insertOrIgnoreReturning([['id' => 1]], ['id']);
}
?>
--EXPECTF--
