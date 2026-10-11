--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Support\Facades\Context;

function exact_int(int $v): int { return $v; }

function context_scope_narrows_return(): void
{
    $_result = Context::scope(static fn (): int => exact_int(1));
    /** @psalm-check-type-exact $_result = int */
}
?>
--EXPECTF--
