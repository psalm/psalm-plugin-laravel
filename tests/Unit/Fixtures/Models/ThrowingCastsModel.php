<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class ThrowingCastsModel extends Model
{
    protected $table = 'throwing_casts_models';

    public function getCasts(): array
    {
        throw new \RuntimeException('deliberate casts failure');
    }
}
