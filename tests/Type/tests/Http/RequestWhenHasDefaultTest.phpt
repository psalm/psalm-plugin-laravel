--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.20.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * Laravel 13.20+ declares `@template TReturnDefault = never` on the `InteractsWithData` conditionals. The
 * default is inert today (an unresolved method template drops out of the return union with or without it),
 * so the result without a `$default` carries no extra member; with one, `TReturnDefault` binds to its return.
 */
function when_has_without_default(Request $request): void
{
    $_result = $request->whenHas('key', static fn(): int => 1);
    /** @psalm-check-type-exact $_result = 1|Request&static */
}

function when_has_with_default(Request $request): void
{
    $_result = $request->whenHas('key', static fn(): int => 1, static fn(): string => 'x');
    /** @psalm-check-type-exact $_result = 'x'|1|Request&static */
}

function when_filled_without_default(Request $request): void
{
    $_result = $request->whenFilled('key', static fn(): int => 1);
    /** @psalm-check-type-exact $_result = 1|Request&static */
}

function when_missing_with_default(Request $request): void
{
    $_result = $request->whenMissing('key', static fn(): int => 1, static fn(): string => 'x');
    /** @psalm-check-type-exact $_result = 'x'|1|Request&static */
}
?>
--EXPECTF--
