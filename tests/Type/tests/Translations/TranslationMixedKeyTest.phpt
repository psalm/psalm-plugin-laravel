--FILE--
<?php declare(strict_types=1);

namespace App;

/**
 * A `mixed` key (an element of an untyped array) is resolved like a `string` key, so concatenating the
 * result no longer reports `PossiblyNullOperand` / `PossiblyInvalidOperand` (#1812).
 *
 * @param array{message: mixed} $row
 */
function mixed_key(array $row, mixed $key): string
{
    $_fromMixed = __($key);
    /** @psalm-check-type-exact $_fromMixed = string */

    $_fromOffset = __($row['message']);
    /** @psalm-check-type-exact $_fromOffset = string */

    $_viaTrans = trans($key);
    /** @psalm-check-type-exact $_viaTrans = string */

    return '"' . __($row['message']) . '"';
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of __ cannot be mixed, expecting null|string
MixedArgument on line %d: Argument 1 of __ cannot be mixed, expecting null|string
MixedArgument on line %d: Argument 1 of trans cannot be mixed, expecting null|string
MixedArgument on line %d: Argument 1 of __ cannot be mixed, expecting null|string
