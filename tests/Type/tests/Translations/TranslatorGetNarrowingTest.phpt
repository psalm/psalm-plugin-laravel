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

/**
 * Named arguments can reorder the key out of position 0 (`$locale` first here).
 * The key must be selected by parameter name, not by argument position, or a
 * non-string, non-literal leading named arg (here $_locale, a plain null) is
 * misread as the key and the call falls through to the vendor docblock's
 * `string|array` union instead of narrowing.
 */
$_locale = null;
$_namedKey = app('translator')->get(locale: $_locale, key: 'some.unresolvable.literal.key');
/** @psalm-check-type-exact $_namedKey = string */
?>
--EXPECTF--
