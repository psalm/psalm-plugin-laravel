<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest on Laravel 13+ */
#[WithoutIncrementing]
#[Table(incrementing: true)]
final class WithoutIncrementingKeylessModel extends Model
{
    protected $primaryKey;

    /** @var array<string, string> */
    protected $casts = [
        'enabled' => 'boolean',
    ];
}
