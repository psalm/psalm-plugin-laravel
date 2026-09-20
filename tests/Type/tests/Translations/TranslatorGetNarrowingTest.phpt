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
 * Named arguments can reorder the key out of position 0 (`$replace` first here).
 * The key must be selected by parameter name, not by argument position, or a
 * non-string, non-literal leading named arg (here $_replace, a plain array) is
 * misread as the key and the call falls through to the vendor docblock's
 * `string|array` union instead of narrowing. $replace (not $locale/$fallback,
 * see below) keeps this case isolated from the locale/fallback decline.
 */
$_replace = [];
$_namedKey = app('translator')->get(replace: $_replace, key: 'some.unresolvable.literal.key');
/** @psalm-check-type-exact $_namedKey = string */

/**
 * The literal-key lookup only answers for the booted translator's CURRENT default
 * locale and default fallback (true): a key that is a string in the default locale
 * can be an array in another one, and a disabled fallback can change existence.
 * A call naming either argument must decline entirely (keep the vendor docblock's
 * `string|array` union) rather than narrow using the wrong locale/fallback.
 */
$_localePositional = app('translator')->get('some.other.unresolvable.key', [], 'fr');
/** @psalm-check-type-exact $_localePositional = array<array-key, mixed>|string */

$_localeNamed = app('translator')->get('some.other.unresolvable.key', locale: 'fr');
/** @psalm-check-type-exact $_localeNamed = array<array-key, mixed>|string */

$_fallbackNamed = app('translator')->get('some.other.unresolvable.key', fallback: false);
/** @psalm-check-type-exact $_fallbackNamed = array<array-key, mixed>|string */

// __()/trans() share the same $locale parameter (position 2) and the same hole.
$_transLocale = trans('some.other.unresolvable.key', [], 'fr');
/** @psalm-check-type-exact $_transLocale = array<array-key, mixed>|string */

/**
 * Pin: the function surface also resolves the key by name, not position, when an
 * earlier parameter (here $replace, not $locale/$fallback) is named ahead of it —
 * this call keeps narrowing since no locale/fallback argument is present.
 */
$_transNamedReplace = trans(replace: [], key: 'some.unresolvable.literal.key');
/** @psalm-check-type-exact $_transNamedReplace = string */
?>
--EXPECTF--
