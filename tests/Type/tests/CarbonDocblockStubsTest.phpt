--FILE--
<?php declare(strict_types=1);

/**
 * `@property int|float|string $timestamp` (CarbonInterface.php:60, Traits/Date.php:69) is the setter input;
 * the getter returns `(int)` (Traits/Date.php:1162-1164).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2164
 */

use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

function timestamp_read(Carbon $c, CarbonImmutable $i): string
{
    $fromSupport = $c->timestamp;
    /** @psalm-check-type-exact $fromSupport = int */

    $fromImmutable = $i->timestamp;
    /** @psalm-check-type-exact $fromImmutable = int */

    return 'k:' . $c->timestamp . $i->timestamp . $fromSupport . $fromImmutable;
}

function base_carbon(BaseCarbon $b): string
{
    $fromBase = $b->timestamp;
    /** @psalm-check-type-exact $fromBase = int */

    return 'k:' . $b->timestamp . $fromBase;
}

function timestamp_write(Carbon $c, CarbonImmutable $i): void
{
    $c->timestamp = 1_700_000_000;
    $c->timestamp = '1700000000.5';
    $c->timestamp = 1_700_000_000.5;
    $i->timestamp = '1700000000';
}

function timestamp_write_rejected(Carbon $c): void
{
    $c->timestamp = [1];
}
?>
--EXPECTF--
InvalidPropertyAssignmentValue on line %d: $c->timestamp with declared type 'float|int|string' cannot be assigned type 'list{1}'
