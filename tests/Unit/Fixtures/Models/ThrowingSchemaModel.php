<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Casts\InboundOnlyCast;

final class ThrowingSchemaModel extends Model
{
    protected $casts = [
        'flag' => 'boolean',
        'code' => InboundOnlyCast::class,
    ];

    public function getTable(): string
    {
        throw new \RuntimeException('deliberate schema failure');
    }
}
