--FILE--
<?php declare(strict_types=1);

/**
 * Carbon's docblocks are stricter than its runtime behaviour:
 *   - `diffForHumans($other, $syntax)` documents `@param int|array $syntax`, but the body
 *     casts it `(int)` (Traits/Difference.php:558), so `true` (absolute diff) is valid.
 *   - `@property int|float|string $timestamp` (CarbonInterface.php:60, Traits/Date.php:69)
 *     describes the setter input; the getter returns `(int)` (Traits/Date.php:1162-1164).
 *
 * `CarbonInterface`-typed receivers keep Carbon's `int|array` `$syntax`: redeclaring the interface
 * breaks `MethodSignatureMismatch` checks on subclasses.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2164
 */

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

function diff_bool_syntax(Carbon $c, CarbonImmutable $i): string
{
    return $c->diffForHumans(Carbon::now(), true)
        . $i->diffForHumans(CarbonImmutable::now(), true)
        . $c->diffForHumans(null, ['syntax' => 1])
        . $c->diffForHumans();
}

function alias_bool_syntax(Carbon $c): string
{
    return $c->from(null, true)
        . (string) $c->since(null, true)
        . $c->to(null, true)
        . $c->until(null, true)
        . $c->fromNow(true)
        . $c->toNow(true)
        . $c->ago(true);
}

function timestamp_read(Carbon $c, CarbonImmutable $i): string
{
    $fromSupport = $c->timestamp;
    /** @psalm-check-type-exact $fromSupport = int */

    $fromImmutable = $i->timestamp;
    /** @psalm-check-type-exact $fromImmutable = int */

    return 'k:' . $c->timestamp . $i->timestamp . $fromSupport . $fromImmutable;
}

function timestamp_write(Carbon $c, CarbonImmutable $i): void
{
    $c->timestamp = 1_700_000_000;
    $c->timestamp = '1700000000.5';
    $c->timestamp = 1_700_000_000.5;
    $i->timestamp = '1700000000';
}

// Negative cases: the widening is only `bool`, other types are still rejected.
function still_rejected(Carbon $c): string
{
    $c->timestamp = [1];

    return $c->diffForHumans(null, 'absolute');
}
?>
--EXPECTF--
InvalidPropertyAssignmentValue on line %d: $c->timestamp with declared type 'float|int|string' cannot be assigned type 'list{1}'
InvalidArgument on line %d: Argument 2 of Illuminate\Support\Carbon::diffForHumans expects array<array-key, mixed>|bool|int|null, but 'absolute' provided
