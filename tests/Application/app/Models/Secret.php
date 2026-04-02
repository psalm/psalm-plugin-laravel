<?php

declare(strict_types=1);

namespace App\Models;

use App\Collections\SecretCollection;

/**
 * Uses newCollection() override to specify a custom collection type.
 */
final class Secret extends AbstractUuidModel
{
    /**
     * @param  array<array-key, \Illuminate\Database\Eloquent\Model>  $models
     * @return SecretCollection<int, static>
     */
    public function newCollection(array $models = []): SecretCollection
    {
        return new SecretCollection($models);
    }
}
