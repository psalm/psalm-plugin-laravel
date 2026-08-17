--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Split out of CacheManagerConcreteRepositoryTest.phpt: CacheManager::memo() ships in
// Laravel 12.9.0 (laravel/framework f519ab82, "Introduce memoized cache driver"), so on
// older lines the call is an undefined magic method rather than a typed one. Everything
// else in that file predates 12 and stays ungated.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.9.0');
--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Support\Facades\Cache;

/**
 * memo() declares the Repository interface but always returns the concrete
 * \Illuminate\Cache\Repository, so its concrete-only surface must resolve. See issue #1230.
 */
function facade_memo(): void
{
    $_memo = Cache::memo();
    /** @psalm-check-type-exact $_memo = \Illuminate\Cache\Repository */
}
?>
--EXPECTF--
