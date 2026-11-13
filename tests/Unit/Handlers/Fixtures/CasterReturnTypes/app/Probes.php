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
}
