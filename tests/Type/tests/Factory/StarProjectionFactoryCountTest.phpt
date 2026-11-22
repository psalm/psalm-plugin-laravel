--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\PsalmStarProjectionCapability::skipUnlessSupported();
--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\StarProjectionFactoryCount;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * `count()` replaces the count of a factory and hands its model through. Rebuilt from the bare type
 * arguments, the unknown model of `Factory<*, N>` became `Model` (#2380). Three places build the
 * answer: the base `Factory<TModel, TCount>`, a class with a plugin-synthesized count, and a user
 * method widened to an unknown count.
 *
 * Skipped on a Psalm without star projections.
 */

/**
 * The plugin appends its own `TCount`, so the class is `<TModel, TCount>`.
 *
 * @template TModel of Model
 * @extends Factory<TModel>
 */
abstract class AbstractStarFactory extends Factory
{
    /** @return static */
    public function replaced()
    {
        return static::new();
    }
}

/** @param Factory<Model, int|null> $_factory */
function takes_model_factory(Factory $_factory): void {}

/**
 * @param Factory<*, *> $base
 * @param AbstractStarFactory<*, null> $synthesized
 */
function star_model_is_kept(Factory $base, AbstractStarFactory $synthesized): void
{
    $_base = $base->count(3);
    /** @psalm-check-type-exact $_base = Factory<*, int<2, max>> */

    $_synthesized = $synthesized->count(3);
    /** @psalm-check-type-exact $_synthesized = AbstractStarFactory<*, int<2, max>> */

    $_replaced = $synthesized->replaced();
    /** @psalm-check-type-exact $_replaced = AbstractStarFactory<*, int<2, max>|null> */

    takes_model_factory($base->count(3));
}
--EXPECTF--
ArgumentTypeCoercion on line %d: Argument 1 of App\Sandbox\StarProjectionFactoryCount\takes_model_factory expects Illuminate\Database\Eloquent\Factories\Factory<Illuminate\Database\Eloquent\Model, int|null>, but parent type Illuminate\Database\Eloquent\Factories\Factory<*, int<2, max>> provided
