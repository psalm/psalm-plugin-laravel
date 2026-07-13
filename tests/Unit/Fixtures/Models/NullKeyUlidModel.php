<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
final class NullKeyUlidModel extends Model
{
    use HasUlids;

    protected $primaryKey;
}
