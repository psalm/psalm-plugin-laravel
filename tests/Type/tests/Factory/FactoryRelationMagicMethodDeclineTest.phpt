--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\FactoryRelationMagicDecline;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// #2215 declines: the provider answers nothing, so Psalm reports the call, or resolves it, as before.

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
}

final class Team extends LinkOwner {}

final class User extends LinkOwner
{
    use HasFactory;

    /** @var class-string<UserFactory> */
    protected static $factory = UserFactory::class;

    /** Exists, not a relation. */
    public function password(): string
    {
        return 'x';
    }
}

/**
 * @extends Factory<User>
 * @method int hasDocumented(string $label)
 */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** A user-declared real method named like a forwarder wins, with its own signature. */
    public function hasLinks(int $count, string $extra): static
    {
        return $this->state(['links' => $count, 'extra' => $extra]);
    }
}

// ----- A pseudo-method Psalm resolves is never shadowed, wherever it is declared -----

/** @method int hasTags(string $label) */
trait TagsPseudo {}

/** @method int hasTags(string $label) */
interface TagsContract {}

/** @method int hasTags(string $label) */
final class TagsHelper {}

interface RealTagsContract
{
    public function hasTags(string $label): int;
}

/**
 * Abstract, so the interface method stays unimplemented here; Psalm still dispatches to it.
 *
 * @extends Factory<User>
 */
abstract class RealContractFactory extends Factory implements RealTagsContract
{
}

/** @extends Factory<User> */
final class TraitPseudoFactory extends Factory
{
    use TagsPseudo;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/** @extends Factory<User> */
final class InterfacePseudoFactory extends Factory implements TagsContract
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * @extends Factory<User>
 * @mixin TagsHelper
 */
final class MixinPseudoFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * A user `__call` owns every name it serves.
 *
 * @extends Factory<User>
 */
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

/** No model binding at all: TModel is unresolved. */
abstract class PhantomFactory extends Factory
{
}

// Typo: still reported.
$_typo = User::factory()->hasLnks(2)->create();

// Laravel's prefix test is case-sensitive: `HasTags()` throws at runtime.
$_capital = User::factory()->HasTags()->create();

// Exists but is not a relation (declared non-Relation return): still reported.
$_notRelation = User::factory()->hasPassword()->create();

// `for`/`has`-prefixed names that are not forwarders of a relation: still reported.
$_format = User::factory()->format()->create();
$_forget = User::factory()->forget()->create();
$_hash = User::factory()->hash()->create();

// Model's own relation builders are not relations.
$_builder = User::factory()->hasHasMany()->create();

// A real `hasLinks()` keeps its declared signature.
$_real = User::factory()->hasLinks(2)->create();

// `@method` on the class, a trait, an interface, a mixin: Psalm's own answer and signature stand.
$_classPseudo = User::factory()->hasDocumented('x');
/** @psalm-check-type-exact $_classPseudo = int */;

$_traitPseudo = (new TraitPseudoFactory())->hasTags('x');
/** @psalm-check-type-exact $_traitPseudo = int */;

$_interfacePseudo = (new InterfacePseudoFactory())->hasTags('x');
/** @psalm-check-type-exact $_interfacePseudo = int */;

$_mixinPseudo = (new MixinPseudoFactory())->hasTags('x');
/** @psalm-check-type-exact $_mixinPseudo = int */;

/** @param RealContractFactory $factory */
function realContract(RealContractFactory $factory): void
{
    // A real interface method on an abstract factory is not shadowed either.
    $_realContract = $factory->hasTags('x');
    /** @psalm-check-type-exact $_realContract = int */;
}

// A user `__call` is never shadowed: the provider declines and Psalm's own answer stands (the factory stays sealed
// by the stub's `@method trashed()`, as before).
$_forwarding = (new ForwardingUserFactory())->hasTags()->create();

// Only `Macroable::__callStatic` serves a static call, and it knows no `has*`: not a forwarder.
$_static = UserFactory::hasTags();

// Wrong argument shapes on a proven forwarder are checked against Laravel's signature. A string is a possible
// callable to Psalm, so `hasTags('two')` is not reported.
$_badCount = User::factory()->hasTags(true)->create();
$_badState = User::factory()->hasTags(2, new \stdClass())->create();
$_badFor = User::factory()->forLinks(2)->create();
$_badSequence = User::factory()->hasTags(['a' => 1], ['b' => 2], 'c')->create();

/** @param PhantomFactory $factory */
function phantom(PhantomFactory $factory): void
{
    $_phantom = $factory->hasLinks()->create();
}

/** @param Factory<User> $factory */
function bareTypo(Factory $factory): void
{
    $_bareTypo = $factory->hasLnks()->create();
}

/** @param Factory<Model> $factory */
function anyModel(Factory $factory): void
{
    $_anyModel = $factory->hasLinks()->create();
}

/** @param Factory<User|Team> $factory */
function modelUnion(Factory $factory): void
{
    $_modelUnion = $factory->hasLinks()->create();
}

/** @param Factory<User>|Factory<Team> $factory */
function receiverUnion(Factory $factory): void
{
    $_receiverUnion = $factory->hasLinks()->create();
}
?>
--EXPECTF--
MissingTemplateParam on line %d: App\Sandbox\FactoryRelationMagicDecline\PhantomFactory has missing template params when extending Illuminate\Database\Eloquent\Factories\Factory, expecting at least 1
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::haslnks does not exist
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags does not exist
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::haspassword does not exist
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::format does not exist
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::forget does not exist
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::hash does not exist
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::hashasmany does not exist
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
TooFewArguments on line %d: Too few arguments for App\Sandbox\FactoryRelationMagicDecline\UserFactory::hasLinks - expecting extra to be passed
TooFewArguments on line %d: Too few arguments for method App\Sandbox\FactoryRelationMagicDecline\UserFactory::haslinks saw 1
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\ForwardingUserFactory::hastags does not exist
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags does not exist
InvalidScalarArgument on line %d: Argument 1 of App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags expects Illuminate\Database\Eloquent\Factories\Sequence|array<string, mixed>|callable[impure]|int|null, but true provided
InvalidArgument on line %d: Argument 2 of App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags expects Illuminate\Database\Eloquent\Factories\Sequence|array<string, mixed>|callable[impure]|null, but stdClass provided
InvalidArgument on line %d: Argument 1 of App\Sandbox\FactoryRelationMagicDecline\UserFactory::forlinks expects Illuminate\Database\Eloquent\Factories\Sequence|array<string, mixed>|callable[impure]|null, but 2 provided
InvalidArgument on line %d: Argument 3 of App\Sandbox\FactoryRelationMagicDecline\UserFactory::hastags expects array<string, mixed>, but 'c' provided
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicDecline\PhantomFactory::haslinks does not exist
MixedMethodCall on line %d: Cannot determine the type of $factory->haslinks() when calling method create
UndefinedMagicMethod on line %d: Magic method Illuminate\Database\Eloquent\Factories\Factory::haslnks does not exist
MixedMethodCall on line %d: Cannot determine the type of $factory->haslnks() when calling method create
UndefinedMagicMethod on line %d: Magic method Illuminate\Database\Eloquent\Factories\Factory::haslinks does not exist
MixedMethodCall on line %d: Cannot determine the type of $factory->haslinks() when calling method create
UndefinedMagicMethod on line %d: Magic method Illuminate\Database\Eloquent\Factories\Factory::haslinks does not exist
MixedMethodCall on line %d: Cannot determine the type of $factory->haslinks() when calling method create
UndefinedMagicMethod on line %d: Magic method Illuminate\Database\Eloquent\Factories\Factory::haslinks does not exist
MixedMethodCall on line %d: Cannot determine the type of $factory->haslinks() when calling method create
