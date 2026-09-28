--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * A userland Manager subclass. driver() is declared `@return mixed` in Laravel;
 * a literal driver name should narrow to the DECLARED return type of the
 * matching `create{Studly}Driver()` method (#1392).
 */
class LiteralFooDriver
{
}

class LiteralBarDriver
{
}

class LiteralExampleManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): LiteralFooDriver
    {
        return new LiteralFooDriver();
    }

    protected function createBarDriver(): LiteralBarDriver
    {
        return new LiteralBarDriver();
    }
}

function literal_injected(LiteralExampleManager $manager): void
{
    $_foo = $manager->driver('foo');
    /** @psalm-check-type-exact $_foo = LiteralFooDriver */

    $_bar = $manager->driver('bar');
    /** @psalm-check-type-exact $_bar = LiteralBarDriver */
}
?>
--EXPECTF--
