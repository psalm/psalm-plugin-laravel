--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.5.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * `Manager::driver()` only accepts a `\UnitEnum` argument (via `enum_value()`)
 * from Laravel 13.5.0 onward (laravel/framework#59659); below that its param is
 * plain `string|null` and passing an enum is a genuine `InvalidArgument`, not
 * something our handler should silently narrow through. Split out of
 * ManagerDriverDeclinesTest.phpt because that file must also pass on the
 * Laravel 12.14 / 13.3 floor, where this call itself is a type error (#1392).
 */
enum DriverEnum
{
    case Foo;
}

class EnumDeclineFooDriver
{
}

class EnumDeclineManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): EnumDeclineFooDriver
    {
        return new EnumDeclineFooDriver();
    }
}

function enum_argument(EnumDeclineManager $manager, DriverEnum $driver): void
{
    $_enum = $manager->driver($driver);
    /** @psalm-check-type-exact $_enum = mixed */
}
?>
--EXPECTF--
