--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Support\Facades\Cache;

function exact_int(int $v): int { return $v; }
function exact_string(string $v): string { return $v; }

function cache_flexible_narrows_return(): void
{
    $_result = Cache::flexible('k', [60, 600], static fn (): int => exact_int(1));
    /** @psalm-check-type-exact $_result = int */

    $_string = Cache::flexible('k', [60, 600], static fn (): string => exact_string('x'));
    /** @psalm-check-type-exact $_string = string */
}

function cache_sear_narrows_return(): void
{
    $_result = Cache::sear('k', static fn (): int => exact_int(1));
    /** @psalm-check-type-exact $_result = int */
}
?>
--EXPECTF--
