<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Model;

final class UniqueStringIdModel extends Model
{
    use HasUniqueStringIds;

    public function newUniqueId(): string
    {
        return 'custom-string-id';
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return \is_string($value) && $value !== '';
    }
}
