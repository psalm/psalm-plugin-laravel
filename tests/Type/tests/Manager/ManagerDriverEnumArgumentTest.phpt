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
 * ManagerDriverCreatorUnionTest.phpt because that file must also pass on the
 * Laravel 12.20 / 13.3 floor, where this call itself is a type error (#1392).
 * The enum name is not statically resolvable, so the call falls back to the
 * creator union (#1738).
 */
enum DriverEnum
{
    case Foo;
}

class EnumFooDriver
{
}

class EnumManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): EnumFooDriver
    {
        return new EnumFooDriver();
    }
}

function enum_argument(EnumManager $manager, DriverEnum $driver): void
{
    $_enum = $manager->driver($driver);
    /** @psalm-check-type-exact $_enum = EnumFooDriver */
}
?>
--EXPECTF--
