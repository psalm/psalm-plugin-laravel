<?php

declare(strict_types=1);

namespace App\Builders;

/**
 * Concrete, non-generic leaf two levels below Builder
 * (InvoiceDeepBuilder extends AbstractPluckableBuilder<Invoice> extends Builder<TModel>).
 * Regression fixture for issue #1287: pluck() must resolve TModel through the
 * flattened template_extended_params chain, not just a direct Builder subclass.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1287
 *
 * @extends AbstractPluckableBuilder<\App\Models\Invoice>
 */
final class InvoiceDeepBuilder extends AbstractPluckableBuilder {}
