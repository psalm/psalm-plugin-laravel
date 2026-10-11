--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as CookieInstance;

function exact_string(string $v): string { return $v; }

function cookie_queued_default_null(): void
{
    $_result = Cookie::queued('name');
    /** @psalm-check-type-exact $_result = CookieInstance|null */
}

function cookie_queued_typed_default(): void
{
    $_result = Cookie::queued('name', exact_string('fallback'));
    /** @psalm-check-type-exact $_result = CookieInstance|string */
}
?>
--EXPECTF--
