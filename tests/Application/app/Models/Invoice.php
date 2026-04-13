<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\InvoiceBuilder;
use App\Collections\InvoiceCollection;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Billing document for a completed work order.
 *
 * Custom builder and collection without @template parameters — tests the
 * builderType() and collectionType() branches that return plain TNamedObject
 * instead of TGenericObject, avoiding TooManyTemplateParams.
 *
 * @see InvoiceBuilder — extends Builder<Invoice> without declaring its own @template.
 * @see InvoiceCollection — extends Collection<int, Invoice> without declaring its own @template.
 *
 * @property string $invoice_number Human-readable invoice identifier (e.g. "INV-2024-001")
 * @property string $status         Lifecycle status: draft, sent, paid, void
 */
#[UseEloquentBuilder(InvoiceBuilder::class)]
#[CollectedBy(InvoiceCollection::class)]
final class Invoice extends Model
{
    protected $table = 'invoices';

    /**
     * @psalm-return BelongsTo<WorkOrder>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Get the entity this invoice bills.
     *
     * Uses @psalm-return to test the Psalm-native annotation path.
     *
     * @psalm-return MorphTo<Customer|Supplier, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
