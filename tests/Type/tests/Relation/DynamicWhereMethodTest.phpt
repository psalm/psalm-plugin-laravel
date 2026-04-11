--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tests for dynamic where{Column} method resolution on Eloquent Relation chains.
 *
 * Laravel's Builder::__call converts whereTitle('foo') to where('title', '=', 'foo').
 * With dynamicWhereMethods enabled, these are resolved on relation chains when the
 * column exists in the model's @property annotations.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/647
 */

// Single-word column on BelongsToMany: whereName matches @property string $name
function test_dynamic_where_single_word_column(): void {
    /** @var BelongsToMany<Part, WorkOrder> $r */
    $r = (new WorkOrder())->parts();
    $_ = $r->whereName('Brake Pads');
    /** @psalm-check-type-exact $_ = BelongsToMany<Part, WorkOrder> */
}

// Multi-word column: wherePartNumber matches @property string $part_number (underscore→camel normalisation)
function test_dynamic_where_multi_word_column(): void {
    /** @var BelongsToMany<Part, WorkOrder> $r */
    $r = (new WorkOrder())->parts();
    $_ = $r->wherePartNumber('BP-1234');
    /** @psalm-check-type-exact $_ = BelongsToMany<Part, WorkOrder> */
}

// Chained dynamic where calls both preserve the Relation type
function test_dynamic_where_chained(): void {
    /** @var BelongsToMany<Part, WorkOrder> $r */
    $r = (new WorkOrder())->parts();
    $_ = $r->whereName('Brake Pads')->wherePartNumber('BP-1234');
    /** @psalm-check-type-exact $_ = BelongsToMany<Part, WorkOrder> */
}

// Mixed chain: declared Builder::where() + dynamic where — both preserve the Relation type
function test_dynamic_where_mixed_with_declared_where(): void {
    /** @var BelongsToMany<Part, WorkOrder> $r */
    $r = (new WorkOrder())->parts();
    $_ = $r->where('unit_price', '>', 10)->whereName('Brake Pads');
    /** @psalm-check-type-exact $_ = BelongsToMany<Part, WorkOrder> */
}

// Terminal call after dynamic where: get() returns the custom collection
function test_dynamic_where_then_terminal(): void {
    /** @var BelongsToMany<Part, WorkOrder> $r */
    $r = (new WorkOrder())->parts();
    $_ = $r->whereName('Brake Pads')->get();
    /** @psalm-check-type-exact $_ = \App\Collections\PartCollection<int, App\Models\Part> */
}

// HasOne relation: whereInvoiceNumber matches @property string $invoice_number
function test_dynamic_where_on_has_one(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->whereInvoiceNumber('INV-2024-001');
    /** @psalm-check-type-exact $_ = HasOne<Invoice, WorkOrder> */
}

// HasOne: multi-word property matching
function test_dynamic_where_has_one_multi_word(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->whereStatus('paid');
    /** @psalm-check-type-exact $_ = HasOne<Invoice, WorkOrder> */
}

// HasMany relation: whereMake matches @property string $make on Vehicle
function test_dynamic_where_on_has_many(): void {
    /** @var HasMany<Vehicle, Customer> $r */
    $r = (new Customer())->vehicles();
    $_ = $r->whereMake('Toyota');
    /** @psalm-check-type-exact $_ = HasMany<Vehicle, Customer> */
}

// Declared where* methods (e.g. whereDate) are NOT intercepted — they go through the
// normal mixin path and return the correct Relation type without triggering dynamic where.
function test_declared_where_methods_not_intercepted(): void {
    /** @var BelongsToMany<Part, WorkOrder> $r */
    $r = (new WorkOrder())->parts();
    $_ = $r->whereDate('created_at', '2024-01-01');
    /** @psalm-check-type-exact $_ = BelongsToMany<Part, WorkOrder> */
}

// Non-existent column: whereNonExistent is accepted (dynamic where opt-in is on)
// but returns mixed because columnMatchesDynamicWhere returns false — no Relation type preserved.
function test_dynamic_where_non_existent_column_returns_mixed(): void {
    /** @var BelongsToMany<Part, WorkOrder> $r */
    $r = (new WorkOrder())->parts();
    $_ = $r->whereNonExistentColumn('foo');
    /** @psalm-check-type-exact $_ = mixed */
}
?>
--EXPECTF--
