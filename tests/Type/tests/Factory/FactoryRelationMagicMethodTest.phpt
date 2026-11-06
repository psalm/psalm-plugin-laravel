--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\FactoryRelationMagic;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// #2215: `has{Relation}()` / `for{Relation}()` are served by Factory::__call when TModel has the relation.

trait HasTags
{
    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}

abstract class BaseModel extends Model
{
    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}

final class Link extends Model {}
final class Tag extends Model {}
final class Profile extends Model {}
final class Team extends Model {}

final class Question extends Model
{
    use HasFactory;

    /** @var class-string<QuestionFactory> */
    protected static $factory = QuestionFactory::class;

    /** @return HasMany<Question, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<Question, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}

final class User extends BaseModel
{
    use HasFactory;
    use HasTags;

    /** @var class-string<UserFactory> */
    protected static $factory = UserFactory::class;

    /** @return HasMany<Link, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<Question, $this> */
    public function questionsSent(): HasMany
    {
        return $this->hasMany(Question::class, 'from_user_id');
    }
}

/** @extends Factory<User> */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** @return static */
    public function admin()
    {
        return $this->state(['admin' => true]);
    }
}

/** @extends Factory<Question> */
final class QuestionFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * Explicit count: the user's own contract, no synthesized `TCount`.
 *
 * @extends Factory<User, null>
 */
final class SingleUserFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * #677 abstract generic base.
 *
 * @template TModel of Model
 * @extends Factory<TModel>
 */
abstract class AbstractUserFactory extends Factory
{
}

/**
 * Leaf of the abstract generic base: the model is bound through it.
 *
 * @extends AbstractUserFactory<User>
 */
final class LeafUserFactory extends AbstractUserFactory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

// ----- Single and counted, the chain keeps the concrete class -----
$_single = User::factory()->hasLinks(2)->create();
/** @psalm-check-type-exact $_single = User */;

$_counted = User::factory(3)->hasLinks()->create();
/** @psalm-check-type-exact $_counted = Collection<int, User> */;

$_chained = User::factory(3)->hasLinks(2)->admin()->forTeam()->make();
/** @psalm-check-type-exact $_chained = Collection<int, User> */;

// ----- Every relation shape: HasMany, BelongsTo, BelongsToMany (trait), HasOne (inherited), self-referencing -----
$_shapes = User::factory()->hasLinks(1)->forTeam()->hasTags(2)->hasProfile()->create();
/** @psalm-check-type-exact $_shapes = User */;

$_selfRef = Question::factory()->hasChildren(3)->forParent()->create();
/** @psalm-check-type-exact $_selfRef = Question */;

// The suffix is `Str::camel()`ed: `QuestionsSent` and `_questions_sent` both name `questionsSent()`.
$_camel = User::factory()->hasQuestionsSent(2)->has_questions_sent()->create();
/** @psalm-check-type-exact $_camel = User */;

// ----- Argument shapes Laravel accepts -----
$_args = User::factory()
    ->hasLinks()
    ->hasLinks(3)
    ->hasLinks(['url' => 'a'])
    ->hasLinks(fn(array $attributes): array => ['url' => $attributes['x'] ?? 'b'])
    ->hasLinks(2, ['url' => 'c'])
    ->hasLinks(2, fn(array $attributes, ?Model $parent): array => ['p' => $parent?->getKey(), 'a' => $attributes])
    ->hasLinks(new Sequence(['url' => 'a'], ['url' => 'b']))
    ->hasLinks(['url' => 'a'], ['url' => 'b'], ['url' => 'c'])
    ->forTeam()
    ->forTeam(['name' => 'x'])
    ->forTeam(fn(array $attributes): array => $attributes)
    ->create();
/** @psalm-check-type-exact $_args = User */;

// Laravel coalesces a null state (`?? []`), and an unpacked argument list may hold any position's value.
$_nullState = User::factory()->forTeam(null)->hasLinks(2, null)->create();
/** @psalm-check-type-exact $_nullState = User */;

$_unpacked = User::factory()->hasLinks(...[])->hasLinks(...[2])->hasLinks(...[2, ['url' => 'a']])->forTeam(...[['name' => 'x']])->create();
/** @psalm-check-type-exact $_unpacked = User */;

// ----- Receiver shapes -----
/** @param Factory<User> $factory */
function bare(Factory $factory): void
{
    $_bare = $factory->hasLinks(2)->forTeam()->create();
    /** @psalm-check-type-exact $_bare = User|Collection<int, User> */;
}

/** @param UserFactory<null>|UserFactory<int<2, max>> $factory */
function union(UserFactory $factory): void
{
    $_union = $factory->hasLinks()->create();
    /** @psalm-check-type-exact $_union = User|Collection<int, User> */;
}

/** @param AbstractUserFactory<User> $factory */
function abstractBase(AbstractUserFactory $factory): void
{
    $_abstractBase = $factory->hasLinks()->create();
    /** @psalm-check-type-exact $_abstractBase = User|Collection<int, User> */;
}

$held = User::factory(2);
$_held = $held->hasLinks()->hasTags()->create();
/** @psalm-check-type-exact $_held = Collection<int, User> */;

$_explicit = (new SingleUserFactory())->hasLinks()->forTeam()->create();
/** @psalm-check-type-exact $_explicit = User */;

$_leaf = LeafUserFactory::times(3)->hasLinks()->create();
/** @psalm-check-type-exact $_leaf = Collection<int, User> */;

$_leafSingle = (new LeafUserFactory())->hasLinks()->create();
/** @psalm-check-type-exact $_leafSingle = User */;

$_final = Question::factory(2)->hasChildren()->create();
/** @psalm-check-type-exact $_final = Collection<int, Question> */;
?>
--EXPECTF--
