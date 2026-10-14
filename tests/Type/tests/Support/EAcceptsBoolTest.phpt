--FILE--
<?php declare(strict_types=1);

/**
 * Laravel's e() leaves $value untyped and helpers.php is not strict, so htmlspecialchars()
 * coerces a bool ('1' / ''). Blade's `{{ $flag }}` compiles to e($flag).
 */
function test_e_accepts_true(): string
{
    return e(true);
}

function test_e_accepts_bool(bool $flag): string
{
    return e($flag);
}
?>
--EXPECTF--
