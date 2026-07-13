<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class ThrowingRuntimeConfigurationModel extends Model
{
    protected $table = 'throwing_runtime_configuration_models';

    protected $casts = ['flag' => 'boolean'];

    public function getHidden(): array
    {
        throw new \RuntimeException('deliberate runtime-configuration failure');
    }
}
