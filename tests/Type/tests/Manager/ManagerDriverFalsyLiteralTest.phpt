--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * Laravel resolves the argument with `enum_value($driver) ?: getDefaultDriver()`
 * (Manager.php) — a FALSY literal (`''` or `'0'`) is treated as "no driver
 * given" and falls through to the default, not to a same-named creator (#1392).
 */
class FalsyFooDriver
{
}

class FalsyZeroDriver
{
}

class FalsyLiteralManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): FalsyFooDriver
    {
        return new FalsyFooDriver();
    }

    // Exists specifically so a bug that used '0'/'' literally, instead of
    // falling through to the default, would produce a DIFFERENT (wrong) type.
    protected function create0Driver(): FalsyZeroDriver
    {
        return new FalsyZeroDriver();
    }
}

function falsy_zero_uses_the_default_driver(FalsyLiteralManager $manager): void
{
    $_zero = $manager->driver('0');
    /** @psalm-check-type-exact $_zero = FalsyFooDriver */
}

function falsy_empty_string_uses_the_default_driver(FalsyLiteralManager $manager): void
{
    $_empty = $manager->driver('');
    /** @psalm-check-type-exact $_empty = FalsyFooDriver */
}
?>
--EXPECTF--
