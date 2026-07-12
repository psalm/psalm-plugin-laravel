--FILE--
<?php declare(strict_types=1);

namespace App;

/**
 * A bare trans() call narrows to the concrete resolved Translator (not just
 * the Contracts\Translation\Translator interface it declares), so
 * concrete-only methods like has() resolve without UndefinedInterfaceMethod.
 */
$_translator = trans();
/** @psalm-check-type-exact $_translator = \Illuminate\Translation\Translator */

$_has = trans()->has('some.key');
/** @psalm-check-type-exact $_has = bool */

// Literal-key resolution is untouched by the zero-arg narrowing.
$_literal = trans('any.literal.key');
/** @psalm-check-type-exact $_literal = string */

// Only the truly zero-arg form narrows; an explicit null key stays on the
// vendor docblock's conditional (Laravel branches on is_null($key), but the
// plugin narrows by argument count only).
$_nullKey = trans(null);
/** @psalm-check-type-exact $_nullKey = \Illuminate\Contracts\Translation\Translator */

// A leading spread hides the argument count: an empty spread returns the
// translator, a non-empty one returns the lookup result. The sound union keeps
// the translator possibility that the vendor conditional would otherwise drop.
/** @psalm-var list{0?: string} $keys */
$keys = [];
$_transSpread = trans(...$keys);
/** @psalm-check-type-exact $_transSpread = \Illuminate\Contracts\Translation\Translator|string */
?>
--EXPECTF--
