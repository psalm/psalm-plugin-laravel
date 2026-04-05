<?php

declare(strict_types=1);

namespace App\Collections;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

/**
 * Custom collection without its own @template parameters.
 *
 * Extends Collection with concrete types instead of declaring @template TKey/@template TModel.
 * Tests that the plugin returns a plain TNamedObject (InvoiceCollection) instead of
 * TGenericObject to avoid TooManyTemplateParams errors.
 *
 * @extends Collection<int, Invoice>
 */
final class InvoiceCollection extends Collection {}
