<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Ramsey\Uuid\UuidInterface;

/**
 * @property-read UuidInterface $uuid
 */
abstract class AbstractUuidModel extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model): void {
            $model->setAttribute('uuid', Str::uuid());
        });
    }
}
