--FILE--
<?php declare(strict_types=1);

namespace App;

use Countable;
use Illuminate\Cookie\CookieJar;
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

function cookie_queued_closure_default(): void
{
    $_result = Cookie::queued('name', static fn (): string => exact_string('fallback'));
    /** @psalm-check-type-exact $_result = CookieInstance|string */
}

function cookie_queued_untyped_closure_default(): void
{
    $_result = Cookie::queued('name', fn () => exact_string('fallback'));
    /** @psalm-check-type-exact $_result = CookieInstance|string */
}

function cookie_jar_queued_default_null(CookieJar $jar): void
{
    $_result = $jar->queued('name');
    /** @psalm-check-type-exact $_result = CookieInstance|null */
}

function cookie_jar_queued_typed_default(CookieJar $jar): void
{
    $_result = $jar->queued('name', exact_string('fallback'));
    /** @psalm-check-type-exact $_result = CookieInstance|string */
}

function cookie_jar_queued_closure_default(CookieJar $jar): void
{
    $_result = $jar->queued('name', static fn (): string => exact_string('fallback'));
    /** @psalm-check-type-exact $_result = CookieInstance|string */
}
/**
 * @template T of string
 * @param T $fallback
 * @return CookieInstance|T
 */
function cookie_queued_bounded_template_default(string $fallback): CookieInstance|string
{
    return Cookie::queued('name', $fallback);
}

/**
 * @template T of string
 * @param T $fallback
 * @return CookieInstance|T
 */
function cookie_jar_queued_bounded_template_default(CookieJar $jar, string $fallback): CookieInstance|string
{
    return $jar->queued('name', $fallback);
}

/** @param iterable<int, int> $fallback */
function cookie_queued_iterable_default(iterable $fallback): void
{
    $_result = Cookie::queued('name', $fallback);
    /** @psalm-check-type-exact $_result = CookieInstance|iterable<int, int> */
}

function cookie_jar_queued_interface_default(CookieJar $jar, Countable $fallback): void
{
    $_result = $jar->queued('name', $fallback);
    /** @psalm-check-type-exact $_result = Countable|CookieInstance */
}

/**
 * @template T of \stdClass
 * @param T $fallback
 * @return CookieInstance|T
 */
function cookie_queued_object_bounded_template_default(\stdClass $fallback): CookieInstance|\stdClass
{
    return Cookie::queued('name', $fallback);
}
?>
--EXPECTF--
