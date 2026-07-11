--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.5.0');
--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;

enum CacheStore
{
    case Redis;
    case File;
}

/**
 * Laravel 13.5.0 widened store()/driver()/memo() to accept a \UnitEnum name, so
 * passing an enum case must not raise an InvalidArgument error.
 */
function enum_store_accepts_enum(CacheManager $manager): void
{
    Cache::store(CacheStore::Redis);
    $manager->driver(CacheStore::File);
}
?>
--EXPECTF--
