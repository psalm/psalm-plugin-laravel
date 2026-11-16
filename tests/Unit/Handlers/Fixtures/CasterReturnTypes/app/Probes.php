<?php

declare(strict_types=1);

namespace CasterReturnFixture;

use CasterReturnFixture\Models\Product;

function probes(Product $product): void
{
    $_generic = $product->generic_price;
    /** @psalm-trace $_generic */

    $_native = $product->native_price;
    /** @psalm-trace $_native */

    $_documented = $product->documented_price;
    /** @psalm-trace $_documented */

    $_traitSelf = $product->trait_self;
    /** @psalm-trace $_traitSelf */

    $_traitStatic = $product->trait_static;
    /** @psalm-trace $_traitStatic */

    $_staticBase = $product->static_base;
    /** @psalm-trace $_staticBase */

    $_parent = $product->parent_ret;
    /** @psalm-trace $_parent */

    $_inheritedDoc = $product->inherited_doc;
    /** @psalm-trace $_inheritedDoc */

    $_childTraitSelf = $product->child_trait_self;
    /** @psalm-trace $_childTraitSelf */

    $_childTraitParent = $product->child_trait_parent;
    /** @psalm-trace $_childTraitParent */

    $_multi = $product->multi;
    /** @psalm-trace $_multi */
}
