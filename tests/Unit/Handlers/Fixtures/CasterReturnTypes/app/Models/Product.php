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
        ];
    }
}
