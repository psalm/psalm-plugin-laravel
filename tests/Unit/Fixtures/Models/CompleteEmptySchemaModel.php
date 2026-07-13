<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Casts\InboundOnlyCast;

final class CompleteEmptySchemaModel extends Model
{
    protected $table = 'complete_empty_schema_models';

    protected $casts = [
        'flag' => 'boolean',
        'code' => InboundOnlyCast::class,
    ];
}
