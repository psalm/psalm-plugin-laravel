--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
}

/**
 * With `$model` declared, the plugin auto-injects `@extends Factory<Task>`
 * so `createOne()`/`makeOne()` return the right model type — no annotation needed.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/780
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

$_resolved = (new TaskFactory())->makeOne();
/** @psalm-check-type-exact $_resolved = Task */;

/**
 * Explicit template binding still works — users can always opt in with
 * `@extends Factory<ConcreteModel>`.
 *
 * @extends Factory<Task>
 */
class ExplicitTaskFactory extends Factory
{
    protected $model = Task::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

$_explicit = (new ExplicitTaskFactory())->makeOne();
/** @psalm-check-type-exact $_explicit = Task */;
?>
--EXPECTF--
