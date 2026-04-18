--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
}

/**
 * Standard Laravel pattern: extend Factory without specifying the template.
 * Laravel resolves the target model via naming convention at runtime
 * (TaskFactory → Task). The plugin should suppress MissingTemplateParam
 * for this common case.
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

/**
 * Explicit template binding still works — users who want type-safe
 * create()/make() calls can opt in with @extends Factory<ConcreteModel>.
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
?>
--EXPECTF--
