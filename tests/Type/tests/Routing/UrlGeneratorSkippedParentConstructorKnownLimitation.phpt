--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Routing\UrlGenerator;

// Known limitation: SuppressHandler marks UrlGenerator::$routes and $request as initialized for
// every subclass, because the UrlGenerator stub hides the vendor constructor body from Psalm.
// A subclass that never calls parent::__construct() is therefore not reported, although
// current() would fail at runtime on the null request. See the UrlGenerator entry in
// SuppressHandler for the rationale.

final class SkippedParentConstructorUrlGenerator extends UrlGenerator
{
    public function __construct()
    {
    }
}
?>
--EXPECTF--
