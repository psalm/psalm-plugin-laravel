--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * A stub that re-declares a facade replaces the sealing flag that Laravel's own `@method` tags set on the
 * vendor class, so each re-declaring stub restates it. A misspelled static call must be reported, not
 * resolved to mixed.
 */
function test_facade_stubs_stay_sealed(): void
{
    App::definitelyNotAMethod();
    Artisan::definitelyNotAMethod();
    Cache::definitelyNotAMethod();
    Context::definitelyNotAMethod();
    Cookie::definitelyNotAMethod();
    DB::definitelyNotAMethod();
    Event::definitelyNotAMethod();
    Redis::definitelyNotAMethod();
    Route::definitelyNotAMethod();
    Schema::definitelyNotAMethod();
}

/** Sealing must not reject a real method that resolves through the `@mixin` or a facade `@method` tag. */
function test_sealed_facades_still_accept_real_methods(): void
{
    Schema::connection('x')->hasTable('users');
    Schema::hasColumn('users', 'id');
    Schema::getTables();
    Route::get('/', static fn(): string => 'ok');
    Event::dispatch('e');
    DB::table('users');
    App::environment();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\App::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\Artisan::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\Cache::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\Context::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\Cookie::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\DB::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\Event::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\Redis::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\Route::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Support\Facades\Schema::definitelynotamethod does not exist
