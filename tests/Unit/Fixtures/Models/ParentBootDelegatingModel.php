<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
final class ParentBootDelegatingModel extends Model
{
    use HasUuids;

    protected static function boot(): void
    {
        parent::boot();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['flag' => 'boolean'];
    }
}
