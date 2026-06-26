<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Casts\InboundOnlyCast;

/**
 * Applies a write-only ({@see InboundOnlyCast}) cast to a string column. The registry must
 * resolve the read type to the column's base type (`string`), NOT `mixed` — exercised by
 * ModelMetadataRegistryTest::inbound_cast_resolves_to_column_base_type.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class InboundCastModel extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'code' => InboundOnlyCast::class,
    ];
}
