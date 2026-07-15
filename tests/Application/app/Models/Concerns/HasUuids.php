<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/** Application wrapper matching projects that customize UUID generation. */
trait HasUuids
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    public function newUniqueId(): string
    {
        return '00000000-0000-4000-8000-000000000000';
    }
}
