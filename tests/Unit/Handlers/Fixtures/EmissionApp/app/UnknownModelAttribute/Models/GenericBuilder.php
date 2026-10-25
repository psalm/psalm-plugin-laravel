<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic custom builder: the model stays a template until a caller binds it.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class GenericBuilder extends Builder
{
    /** `$this` inside a generic builder has an unresolved TModel — must stay silent. */
    public function createUnresolved(): Model
    {
        return $this->create(['nmae_unresolved' => 1]);
    }
}
