--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\FactoryRelationMagicDecline;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// #2215 declines: Psalm reports or resolves the call as before.

final class Link extends Model {}

abstract class LinkOwner extends Model
{
    /** @return HasMany<Link, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    /** @return HasMany<Link, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    /** @return HasMany<Link, $this> */
    public function documented(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    /** @return HasMany<Link, $this> named like the boot macro `hasMacroCollision` (tests/Type/macro-fixtures.php) */
    public function macroCollision(): HasMany
    {
        return $this->hasMany(Link::class);
    }
}

final class Team extends LinkOwner {}

final class User extends LinkOwner
{
    use HasFactory;

    /** @var class-string<UserFactory> */
    protected static $factory = UserFactory::class;

    public function password(): string
    {
        return 'x';
    }
}

/**
 * @extends Factory<User>
 * @method int hasDocumented(string $label)
 */
final class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function hasLinks(int $count, string $extra): static
    {
        return $this->state(['links' => $count, 'extra' => $extra]);
    }
}

/** No model binding: TModel is unresolved. */
abstract class PhantomFactory extends Factory {}

interface RealTagsContract
{
    public function hasTags(string $label): int;
}

/** @extends Factory<User> */
abstract class RealContractFactory extends Factory implements RealTagsContract {}

/** @extends Factory<User> */
final class ForwardingUserFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /**
     * @param string $method
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function __call($method, $parameters): static
    {
        return $this;
    }
}

// Unknown, non-relation, wrongly cased, unrelated and Model-builder names.
$_typo = User::factory()->hasLnks(2);
$_notRelation = User::factory()->hasPassword();
$_capital = User::factory()->HasTags();
$_format = User::factory()->format();
$_forget = User::factory()->forget();
$_hash = User::factory()->hash();
$_builder = User::factory()->hasHasMany();
$_static = UserFactory::hasTags();

// A real method (also an abstract class's interface method), an `@method`, a boot macro and a user `__call` keep
// their own contracts.
$_real = User::factory()->hasLinks(2);
$_pseudo = User::factory()->hasDocumented('x');
/** @psalm-check-type-exact $_pseudo = int */;
$_macro = User::factory()->hasMacroCollision('x');
/** @psalm-check-type-exact $_macro = int */;

function owned(RealContractFactory $contract, ForwardingUserFactory $forwarding): void
{
    $_realContract = $contract->hasTags('x');
    /** @psalm-check-type-exact $_realContract = int */;
    $_forwarding = $forwarding->hasTags();
}

// Wrong argument shapes on a proven forwarder.
$_badCount = User::factory()->hasTags(true);
$_badState = User::factory()->hasTags(2, new \stdClass());
$_badFor = User::factory()->forTags(2);
$_badSequence = User::factory()->hasTags(['a' => 1], ['b' => 2], 'c');

/** @param Factory<User|Team> $models */
function unbound(PhantomFactory $phantom, Factory $models): void
{
    $_phantom = $phantom->hasLinks();
    $_models = $models->hasLinks();
}
?>
--EXPECTF--
MissingTemplateParam on line %d: App\Sandbox\FactoryRelationMagicDecline\PhantomFactory has missing template params when extending Illuminate\Database\Eloquent\Factories\Factory, expecting at least 1
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::haslnks does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::haspassword does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::format does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::forget does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::hash does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::hashasmany does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags does not exist
TooFewArguments on line %d: Too few arguments for App\Sandbox\FactoryRelationMagicDecline\UserFactory::hasLinks - expecting extra to be passed
TooFewArguments on line %d: Too few arguments for method App\Sandbox\FactoryRelationMagicDecline\UserFactory::haslinks saw 1
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\ForwardingUserFactory::hastags does not exist
InvalidScalarArgument on line %d: Argument 1 of App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags expects Illuminate\Database\Eloquent\Factories\Sequence|array<string, mixed>|callable[impure]|int|null, but true provided
InvalidArgument on line %d: Argument 2 of App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags expects Illuminate\Database\Eloquent\Factories\Sequence|array<string, mixed>|callable[impure]|null, but stdClass provided
InvalidArgument on line %d: Argument 1 of App\Sandbox\FactoryRelationMagicDecline\UserFactory::fortags expects Illuminate\Database\Eloquent\Factories\Sequence|array<string, mixed>|callable[impure]|null, but 2 provided
InvalidArgument on line %d: Argument 3 of App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags expects array<string, mixed>, but 'c' provided
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\PhantomFactory::haslinks does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Database\Eloquent\Factories\Factory::haslinks does not exist
