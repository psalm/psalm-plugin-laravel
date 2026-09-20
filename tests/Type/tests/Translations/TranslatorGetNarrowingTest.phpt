--FILE--
<?php declare(strict_types=1);

namespace App;

/**
 * Regression for #1499: Blade's @lang('key') compiles to
 * app('translator')->get('key'), which without this handler falls back to
 * the vendor docblock's `string|array` union and causes a PossiblyInvalidArgument
 * FP on the compiled echo. A literal, unresolvable key narrows to string —
 * same fallback the __()/trans() function surface already uses.
 */
$_get = app('translator')->get('some.unresolvable.literal.key');
/** @psalm-check-type-exact $_get = string */
?>
--EXPECTF--
