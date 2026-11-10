--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PendingRequest is generic (`@template TAsync`) from Laravel 12.43.0; before that there is no bound to mismatch.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.43.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Psalm forwards `Http::withHeaders()` through the facade's `@mixin PendingRequest` on a receiver
 * without template arguments, so the fluent result is `PendingRequest&static` rather than the
 * `PendingRequest<bool>&static` a typed `PendingRequest $pr` receiver gives. Inside a template
 * (`tap()`) the two no longer match, and a typed callback reports a false ArgumentTypeCoercion.
 * The instance and chained forms are unaffected. Caveat documented in
 * stubs/common/Support/Facades/Http.phpstub.
 */
function tap_typed_callback(PendingRequest $pr): void
{
    tap(Http::withHeaders([]), static function (PendingRequest $r): void {
        $r->acceptJson();
    });

    tap($pr->withHeaders([]), static function (PendingRequest $r): void {
        $r->acceptJson();
    });
}
?>
--EXPECTF--
ArgumentTypeCoercion on line %d: Argument 2 of tap expects callable[impure](Illuminate\Http\Client\PendingRequest<bool>):void|null, but parent type Closure[%s](Illuminate\Http\Client\PendingRequest&static):void provided
