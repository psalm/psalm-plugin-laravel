<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom builder without its own @template parameters.
 *
 * Extends Builder with a concrete model type instead of declaring @template TModel.
 * Tests that the plugin returns a plain TNamedObject (InvoiceBuilder) instead of
 * TGenericObject to avoid TooManyTemplateParams errors.
 *
 * @extends Builder<Invoice>
 */
final class InvoiceBuilder extends Builder
{
    public function wherePaid(): self
    {
        return $this->where('status', 'paid');
    }
}
