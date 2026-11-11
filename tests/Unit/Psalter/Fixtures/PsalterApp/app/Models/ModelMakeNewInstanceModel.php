<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * No constructor of its own, but `newInstance()` is customized: `make()` goes through it, `new` does not,
 * so the call is reported and not rewritten.
 */
class ModelMakeNewInstanceModel extends Model
{
    /**
     * @param array<string, mixed> $attributes
     * @param bool $exists
     * @return static
     */
    #[\Override]
    public function newInstance($attributes = [], $exists = false)
    {
        $instance = parent::newInstance($attributes, $exists);
        $instance->setAttribute('marker', 'hook');

        return $instance;
    }
}
