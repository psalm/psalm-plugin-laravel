<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Integer-valued casts over unsigned, nullable-unsigned and string columns, on an incrementing
 * model (so Laravel adds the implicit `id => int` key cast) — exercised by
 * ModelMetadataRegistryTest::integer_casts_keep_the_unsigned_range_of_the_column.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class IntegerCastModel extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'views' => 'integer',
        'rank' => 'int',
        'seen_at' => 'timestamp',
        'label' => 'integer',
    ];
}
