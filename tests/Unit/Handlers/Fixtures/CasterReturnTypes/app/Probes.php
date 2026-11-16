<?php

declare(strict_types=1);

namespace CasterReturnFixture;

use CasterReturnFixture\Models\Product;

function probes(Product $product): void
{
    $_native = $product->native_price;
    /** @psalm-trace $_native */
}
