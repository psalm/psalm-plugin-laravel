--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\HasMany;

    final class RelationMagicPart extends Model {}

    final class RelationMagicGadget extends Model
    {
        /** @return HasMany<RelationMagicPart, $this> */
        public function parts(): HasMany
        {
            return $this->hasMany(RelationMagicPart::class);
        }

        // Undeclared return: the body is parsed and builds a relation.
        public function owner()
        {
            return $this->belongsTo(self::class, 'owner_id');
        }

        // Undeclared return: the body is not a relation builder.
        public function avatar()
        {
            return $this->getAttribute('avatar');
        }
    }
}

namespace Database\Factories {
    use Illuminate\Database\Eloquent\Factories\Factory;

    /** Bound by the naming convention only: no `@extends`, no `$model`. */
    final class RelationMagicGadgetFactory extends Factory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\FactoryRelationMagicConvention {
    use App\Models\RelationMagicGadget;
    use Database\Factories\RelationMagicGadgetFactory;
    use Illuminate\Database\Eloquent\Collection;

    // #2215 on a convention-bound factory, with relations read from an undeclared method body.
    $_single = (new RelationMagicGadgetFactory())->hasParts(2)->forOwner()->create();
    /** @psalm-check-type-exact $_single = RelationMagicGadget */;

    $_counted = RelationMagicGadgetFactory::times(3)->hasParts()->create();
    /** @psalm-check-type-exact $_counted = Collection<int, RelationMagicGadget> */;

    $_notRelation = (new RelationMagicGadgetFactory())->hasAvatar();
}
?>
--EXPECTF--
MissingReturnType on line %d: Method App\Models\RelationMagicGadget::owner does not have a return type, expecting Illuminate\Database\Eloquent\Relations\BelongsTo<App\Models\RelationMagicGadget, App\Models\RelationMagicGadget>
MissingReturnType on line %d: Method App\Models\RelationMagicGadget::avatar does not have a return type
UndefinedMagicMethod on line %d: Magic method Database\Factories\RelationMagicGadgetFactory::hasavatar does not exist
