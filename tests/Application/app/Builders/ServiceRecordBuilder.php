<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom builder without its own @template parameters.
 *
 * Extends Builder with a concrete model type instead of declaring @template TModel.
 * Tests that the plugin returns a plain TNamedObject (ServiceRecordBuilder) instead of
 * TGenericObject to avoid TooManyTemplateParams errors.
 *
 * @extends Builder<ServiceRecord>
 */
final class ServiceRecordBuilder extends Builder
{
    public function whereOpen(): self
    {
        return $this->where('status', 'open');
    }
}
