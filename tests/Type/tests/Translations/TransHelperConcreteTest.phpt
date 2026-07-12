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
?>
--EXPECTF--
