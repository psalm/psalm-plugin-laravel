--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\FactoryRelationMagicCount;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// #2215: how `has{Relation}()` / `for{Relation}()` meet the factory count (#2188).

final class Member extends Model {}

abstract class Owner extends Model
{
    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }
}

final class User extends Owner
{
    use HasFactory;

    /** @var class-string<UserFactory> */
    protected static $factory = UserFactory::class;
}

final class Team extends Owner {}
final class Org extends Owner {}
final class Club extends Owner {}
final class Shop extends Owner {}
final class Crew extends Owner {}
final class Guild extends Owner {}

/** @extends Factory<User> */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    // A call served by `__call` never passes the body rule (a macro registered after boot may own the name), so
    // this method answers what a failed proof always answers: the receiver at the unknown count.
    public function withMembers(): static
    {
        return $this->hasMembers(2);
    }
}

$_kept = User::factory(3)->hasMembers()->create();
/** @psalm-check-type-exact $_kept = Collection<int, User> */;

$_wrapped = User::factory(3)->withMembers()->create();
/** @psalm-check-type-exact $_wrapped = User|Collection<int, User> */;

$_wrappedSingle = User::factory()->withMembers()->create();
/** @psalm-check-type-exact $_wrappedSingle = User|Collection<int, User> */;

// ----- Override gate: a `F<null>` receiver may hold a descendant -----

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * Declares a `Factory` method, so it owns its count: `has()` and `hasMembers()` on a `TeamFactory<null>` answer the
 * unknown count alike.
 */
final class OwningTeamFactory extends TeamFactory
{
    #[\Override]
    public function modelName(): string
    {
        return Team::class;
    }
}

/** @extends Factory<Org> */
class OrgFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/** Declares the name as a real method with a non-factory contract: an `OrgFactory<null>` may hold it. */
final class MembersOrgFactory extends OrgFactory
{
    public function hasMembers(): string
    {
        return 'not a factory';
    }
}

/** @extends Factory<Club> */
class ClubFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/** @method int hasMembers() */
final class PseudoClubFactory extends ClubFactory
{
}

/** @extends Factory<Shop> */
class ShopFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/** @extends Factory<Crew> */
class CrewFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/** Owns every magic name through its own `__call`. */
final class ForwardingCrewFactory extends CrewFactory
{
    /**
     * @param string $method
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function __call($method, $parameters): string
    {
        return $method;
    }
}

/**
 * @template TModel of Owner
 * @extends Factory<TModel>
 */
abstract class OwnerFactory extends Factory
{
}

/**
 * Declares the name, but is bound to `Shop`: an `OwnerFactory<Guild>` can never hold it.
 *
 * @extends OwnerFactory<Shop>
 */
final class MembersShopFactory extends OwnerFactory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function hasMembers(): string
    {
        return 'not a factory';
    }
}

/**
 * @param TeamFactory<null> $team
 * @param OrgFactory<null> $org
 * @param ClubFactory<null> $club
 * @param ShopFactory<null> $shop
 * @param CrewFactory<null> $crew
 * @param Factory<Org> $bareOrg
 * @param OwnerFactory<Guild> $guild
 * @param OwnerFactory<Shop> $ownerShop
 */
function gate(
    TeamFactory $team,
    OrgFactory $org,
    ClubFactory $club,
    ShopFactory $shop,
    CrewFactory $crew,
    Factory $bareOrg,
    OwnerFactory $guild,
    OwnerFactory $ownerShop,
): void {
    $_team = $team->hasMembers()->create();
    /** @psalm-check-type-exact $_team = Team|Collection<int, Team> */;

    // A descendant bound to the receiver's model that declares the name (real or `@method`) or overrides `__call`
    // may answer anything: declined, reported as before. A bare `Factory<Org>` may hold that descendant too.
    $_org = $org->hasMembers();
    $_club = $club->hasMembers();
    $_crew = $crew->hasMembers();
    $_bareOrg = $bareOrg->hasMembers();
    $_ownerShop = $ownerShop->hasMembers();

    // A descendant bound to another model is no conflict.
    $_guild = $guild->hasMembers()->create();
    /** @psalm-check-type-exact $_guild = Guild|Collection<int, Guild> */;

    // No descendant: the receiver's count stands.
    $_shop = $shop->hasMembers()->create();
    /** @psalm-check-type-exact $_shop = Shop */;
}

// A literal construction holds exactly that class, so its descendants cannot answer instead.
$_newOrg = (new OrgFactory())->hasMembers()->create();
/** @psalm-check-type-exact $_newOrg = Org */;

$_staticNewOrg = OrgFactory::new()->hasMembers()->create();
/** @psalm-check-type-exact $_staticNewOrg = Org */;

final class OrgFactoryHelper
{
    public static function new(): OrgFactory
    {
        return new MembersOrgFactory();
    }
}

// Only a literal call on the receiver's own class names it: another class's `new()` may return a descendant.
$_helperOrg = OrgFactoryHelper::new()->hasMembers();
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicCount\OrgFactory::hasmembers does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicCount\ClubFactory::hasmembers does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicCount\CrewFactory::hasmembers does not exist
UndefinedMagicMethod on line %d: Magic method Illuminate\Database\Eloquent\Factories\Factory::hasmembers does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicCount\OwnerFactory::hasmembers does not exist
UndefinedMagicMethod on line %d: Magic method App\Sandbox\FactoryRelationMagicCount\OrgFactory::hasmembers does not exist
