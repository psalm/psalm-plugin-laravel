<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    /** @return array<string, class-string> */
    protected function casts(): array
    {
        return [
            'generic_price' => GenericMoneyCast::class,
            'native_price' => NativeMoneyCast::class,
            'documented_price' => DocumentedMoneyCast::class,
            'trait_self' => TraitSelfCast::class,
            'trait_static' => TraitStaticCast::class,
            'static_base' => StaticBaseCast::class,
            'parent_ret' => ParentReturningCast::class,
            'child_trait_self' => ChildTraitSelfCast::class,
            'child_trait_parent' => ChildTraitParentCast::class,
            'dual' => DualContractCast::class,
            'dual_castable' => DualContractCastable::class,
            'child_native' => ChildNativeCast::class,
            'self_casting' => SelfCastingValue::class,
            'intersection' => IntersectionCastable::class,
            'intersection_cs' => IntersectionClassStringCastable::class,
            'multi' => MultiCastable::class,
            'inherited_doc' => InheritedDocblockCast::class,
        ];
    }
}
