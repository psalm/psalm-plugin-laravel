--FILE--
<?php declare(strict_types=1);

namespace App;

/**
 * __()'s $replace accepts a Closure value (Translator::makeReplacements()
 * branches on instanceof Closure and calls it with the matched inner text),
 * not just scalar|null.
 */
$_closureReplace = __('some.key', ['name' => fn (string $matched): string => strtoupper($matched)]);
/** @psalm-check-type-exact $_closureReplace = string */

// A plain scalar value is still accepted alongside Closure values.
$_scalarReplace = __('some.key', ['name' => 'value']);
/** @psalm-check-type-exact $_scalarReplace = string */

// Literal-key return type is unaffected by the $replace shape.
$_nullKey = __(null, ['name' => fn (string $matched): string => $matched]);
/** @psalm-check-type-exact $_nullKey = null */
?>
--EXPECTF--
