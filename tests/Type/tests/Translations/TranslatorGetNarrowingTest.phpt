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
 * `string|array` union instead of narrowing.
 */
$_replace = [];
$_namedKey = app('translator')->get(replace: $_replace, key: 'some.unresolvable.literal.key');
/** @psalm-check-type-exact $_namedKey = string */

/**
 * Pin: the function surface also resolves the key by name, not position, when an
 * earlier parameter (here $replace) is named ahead of it.
 */
$_transNamedReplace = trans(replace: [], key: 'some.unresolvable.literal.key');
/** @psalm-check-type-exact $_transNamedReplace = string */

/**
 * A trailing unpack can smuggle a $replace into the call without the
 * named-or-positional check ever seeing it: the AST only has two Arg nodes here
 * (the literal key, and the spread), so byNameOrPosition($args, 1, 'replace')
 * finds nothing, even though $rest fills that position at runtime and its
 * interpolation can empty a resolved string. Any unpack anywhere in the
 * argument list declines the lookup entirely.
 */
$rest = [['name' => '']];
$_trailingUnpack = app('translator')->get('some.other.unresolvable.key', ...$rest);
/** @psalm-check-type-exact $_trailingUnpack = array<array-key, mixed>|string */

$_transTrailingUnpack = trans('some.other.unresolvable.key', ...$rest);
/** @psalm-check-type-exact $_transTrailingUnpack = array<array-key, mixed>|string */

/**
 * Provenance: the method surface must narrow only the container-fetched
 * singleton (`app('translator')` / `resolve('translator')`), the exact shape
 * Blade's @lang compiles to. This handler's static state is captured from
 * THAT booted singleton at plugin boot — a `new Translator(...)` receiver is a
 * different, unrelated instance with its own locale and loader, so answering
 * from the booted singleton's lookup state would be provenance-blind. Any
 * other receiver expression keeps the vendor `string|array` union.
 */
$_loader = new class implements \Illuminate\Contracts\Translation\Loader {
    #[\Override]
    public function load($locale, $group, $namespace = null)
    {
        return [];
    }

    #[\Override]
    public function addNamespace($namespace, $hint)
    {
    }

    #[\Override]
    public function addJsonPath($path)
    {
    }

    #[\Override]
    public function namespaces()
    {
        return [];
    }
};
$_provenance = (new \Illuminate\Translation\Translator($_loader, 'fr'))->get('some.other.unresolvable.key');
/** @psalm-check-type-exact $_provenance = array<array-key, mixed>|string */

/**
 * A `$replace` argument runs the resolved value through Laravel's
 * makeReplacements() interpolation, which can turn a non-empty string into ''
 * (e.g. a placeholder replaced with an empty value) — auth.throttle is a real
 * Laravel default translation containing `:seconds`. A resolved non-empty-string
 * must widen to plain string when replace is present; this also covers the
 * pre-existing hole on the __()/trans() function surface, which shares the
 * same lookup path.
 */
$_replaceWidensString = app('translator')->get('auth.throttle', ['seconds' => 5]);
/** @psalm-check-type-exact $_replaceWidensString = string */

$_transReplaceWidensString = trans('auth.throttle', ['seconds' => 5]);
/** @psalm-check-type-exact $_transReplaceWidensString = string */

// No replace argument: still precise (group/array results are also unaffected by replace).
$_noReplaceStaysNonEmpty = app('translator')->get('auth.throttle');
/** @psalm-check-type-exact $_noReplaceStaysNonEmpty = non-empty-string */
?>
--EXPECTF--
