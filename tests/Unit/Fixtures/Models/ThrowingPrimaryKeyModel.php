<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ThrowingPrimaryKeyModel extends Model
{
    use HasUuids;

    protected $table = 'throwing_primary_key_models';

    protected $casts = ['flag' => 'boolean'];

    public function getCasts(): array
    {
        return ['flag' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        throw new \RuntimeException('deliberate primary-key failure');
    }
}
