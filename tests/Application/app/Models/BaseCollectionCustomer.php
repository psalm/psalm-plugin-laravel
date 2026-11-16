<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;

/** A customer whose `newCollection()` override still returns Eloquent's own Collection: not an unknown collection. */
final class BaseCollectionCustomer extends Customer
{
    /**
     * @param array<array-key, \Illuminate\Database\Eloquent\Model> $models
     * @return Collection<array-key, \Illuminate\Database\Eloquent\Model&static>
     */
    #[\Override]
    public function newCollection(array $models = [])
    {
        return parent::newCollection($models);
    }
}
