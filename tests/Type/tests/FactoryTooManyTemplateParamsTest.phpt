--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Intermediate factory layer that overrides new() with @return static<TModel>.
 * This is a common real-world pattern where the base factory adds stricter types.
 *
 * The InvalidReturnType/InvalidReturnStatement errors below are expected —
 * Psalm sees parent::new() as `static` of the parent, not the child.
 *
 * @template TModel of Model
 * @extends Factory<TModel>
 */
abstract class AppFactory extends Factory
{
    /**
     * @param (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed> $attributes
     * @return static<TModel>
     */
    #[\Override]
    final public static function new($attributes = []): static
    {
        return parent::new($attributes);
    }
}

// --- Test 1: Child factory with 0 own template params (the bug case) ---

/**
 * @extends AppFactory<FactoryUser>
 */
final class FactoryUserFactory extends AppFactory
{
    protected $model = FactoryUser::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

class FactoryUser extends Model
{
    use HasFactory;
}

// Must resolve to FactoryUserFactory (no template args), not FactoryUserFactory<FactoryUser>
$_new = FactoryUserFactory::new();
/** @psalm-check-type-exact $_new = FactoryUserFactory */

FactoryUserFactory::new()->count(250)->createQuietly();

$_seq = FactoryUserFactory::new()->sequence(['name' => 'test']);
/** @psalm-check-type-exact $_seq = FactoryUserFactory */

// --- Test 2: Direct Factory subclass without intermediate layer (standard pattern) ---

/** @extends Factory<DirectUser> */
final class DirectUserFactory extends Factory
{
    protected $model = DirectUser::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

class DirectUser extends Model
{
    use HasFactory;
}

// Standard pattern — no intermediate layer, no @return static<TModel> override.
// Should work without interference from the handler.
$_direct = DirectUserFactory::new();
/** @psalm-check-type-exact $_direct = DirectUserFactory */
?>
--EXPECTF--
InvalidReturnType on line %d: %s
InvalidReturnStatement on line %d: %s
