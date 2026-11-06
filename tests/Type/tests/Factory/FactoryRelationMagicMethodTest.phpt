--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Database\Eloquent\Relations\HasOne;

    final class RelationMagicLink extends Model {}
    final class RelationMagicTeam extends Model {}

    trait RelationMagicTags
    {
        /** @return BelongsToMany<RelationMagicTeam, $this> */
        public function tags(): BelongsToMany
        {
            return $this->belongsToMany(RelationMagicTeam::class);
        }
    }

    abstract class RelationMagicBase extends Model
    {
        /** @return HasOne<RelationMagicLink, $this> */
        public function profile(): HasOne
        {
            return $this->hasOne(RelationMagicLink::class);
        }
    }

    final class RelationMagicUser extends RelationMagicBase
    {
        use HasFactory;
        use RelationMagicTags;

        /** @return HasMany<RelationMagicLink, $this> */
        public function links(): HasMany
        {
            return $this->hasMany(RelationMagicLink::class);
        }

        /** @return BelongsTo<RelationMagicTeam, $this> */
        public function team(): BelongsTo
        {
            return $this->belongsTo(RelationMagicTeam::class);
        }

        /** @return HasMany<RelationMagicQuestion, $this> */
        public function questionsSent(): HasMany
        {
            return $this->hasMany(RelationMagicQuestion::class);
        }
    }

    final class RelationMagicQuestion extends Model
    {
        use HasFactory;

        /** @return HasMany<RelationMagicQuestion, $this> */
        public function children(): HasMany
        {
            return $this->hasMany(self::class, 'parent_id');
        }

        // No declared return: the body is parsed.
        public function parent()
        {
            return $this->belongsTo(self::class, 'parent_id');
        }
    }
}

namespace Database\Factories {
    use Illuminate\Database\Eloquent\Factories\Factory;

    // Bound by the naming convention only.
    final class RelationMagicUserFactory extends Factory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }

    final class RelationMagicQuestionFactory extends Factory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\FactoryRelationMagic {
    use App\Models\RelationMagicQuestion;
    use App\Models\RelationMagicUser;
    use Illuminate\Database\Eloquent\Collection;
    use Illuminate\Database\Eloquent\Factories\Factory;
    use Illuminate\Database\Eloquent\Factories\Sequence;

    // #2215: HasMany, BelongsTo, BelongsToMany (trait), HasOne (inherited), camelCase suffix.
    $_single = RelationMagicUser::factory()->hasLinks(2)->forTeam()->hasTags()->hasProfile()->hasQuestionsSent()->create();
    /** @psalm-check-type-exact $_single = RelationMagicUser */;

    $_counted = RelationMagicUser::factory(3)->hasLinks()->create();
    /** @psalm-check-type-exact $_counted = Collection<int, RelationMagicUser> */;

    // Self-referencing HasMany, and a relation read from an undeclared body.
    $_selfRef = RelationMagicQuestion::factory()->hasChildren(3)->forParent()->create();
    /** @psalm-check-type-exact $_selfRef = RelationMagicQuestion */;

    $_args = RelationMagicUser::factory()
        ->hasLinks(['url' => 'a'])
        ->hasLinks(fn(array $attributes): array => $attributes)
        ->hasLinks(2, null)
        ->hasLinks(new Sequence(['url' => 'a'], ['url' => 'b']))
        ->hasLinks(['url' => 'a'], ['url' => 'b'], ['url' => 'c'])
        ->hasLinks(...[])
        ->hasLinks(...[2, ['url' => 'a']])
        ->forTeam(null)
        ->forTeam(['name' => 'x'])
        ->create();
    /** @psalm-check-type-exact $_args = RelationMagicUser */;

    /** @param Factory<RelationMagicUser> $factory */
    function bare(Factory $factory): void
    {
        $_bare = $factory->hasLinks()->create();
        /** @psalm-check-type-exact $_bare = RelationMagicUser|Collection<int, RelationMagicUser> */;
    }
}
?>
--EXPECTF--
MissingReturnType on line %d: Method App\Models\RelationMagicQuestion::parent does not have a return type, expecting Illuminate\Database\Eloquent\Relations\BelongsTo<App\Models\RelationMagicQuestion, App\Models\RelationMagicQuestion>
