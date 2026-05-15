--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Str;

/** When $value is an empty string, Str::lower() returns a literal empty string. */
function test_str_lower_with_empty_string(): void
{
    $_result = Str::lower('');
    /** @psalm-check-type-exact $_result = '' */
}

/** When $value is a non-empty lowercase literal, Str::lower() returns a non-empty lowercase string. */
function test_str_lower_with_non_empty_string(): void
{
    $_result = Str::lower('hello');
    /** @psalm-check-type-exact $_result = non-empty-lowercase-string */
}

/** When $value is an uppercase literal, Str::lower() still returns a non-empty lowercase string. */
function test_str_lower_with_uppercase_string(): void
{
    $_result = Str::lower('HELLO');
    /** @psalm-check-type-exact $_result = non-empty-lowercase-string */
}
?>
--EXPECTF--
