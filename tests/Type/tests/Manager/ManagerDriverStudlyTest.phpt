--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * The creator method name is `create` . Str::studly($driver) . `Driver`, matching
 * Laravel's own Manager::createDriver() (#1392). A snake/kebab-case driver name
 * must resolve through the same studly conversion Laravel uses at runtime.
 */
class MyThingDriver
{
}

class StudlyManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'my_thing';
    }

    protected function createMyThingDriver(): MyThingDriver
    {
        return new MyThingDriver();
    }
}

function studly_injected(StudlyManager $manager): void
{
    $_underscored = $manager->driver('my_thing');
    /** @psalm-check-type-exact $_underscored = MyThingDriver */

    $_kebab = $manager->driver('my-thing');
    /** @psalm-check-type-exact $_kebab = MyThingDriver */
}
?>
--EXPECTF--
