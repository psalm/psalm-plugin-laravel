--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * driver() with no argument falls back to getDefaultDriver(). When that method's
 * body is a single literal `return '...'`, the literal is known statically and the
 * no-arg call narrows exactly like passing the literal explicitly (#1392).
 */
class DefaultFooDriver
{
}

class DefaultDriverManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): DefaultFooDriver
    {
        return new DefaultFooDriver();
    }
}

function default_injected(DefaultDriverManager $manager): void
{
    $_default = $manager->driver();
    /** @psalm-check-type-exact $_default = DefaultFooDriver */
}
?>
--EXPECTF--
