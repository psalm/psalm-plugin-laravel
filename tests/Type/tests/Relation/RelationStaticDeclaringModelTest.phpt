--FILE--
<?php declare(strict_types=1);

// Dedicated sub-namespace: the psalm-tester batches every .phpt into one analysis, so the
// fixture class names must not collide with the other Relation tests (Customer/Order/Book/etc.).
namespace Tests\Psalm\LaravelPlugin\Sandbox\Relation913;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Extended coverage for #913: relation methods template the declaring model as `static`
 * (a Psalm-7 divergence from Laravel's `$this`) and TDeclaringModel is @template-covariant,
 * so chaining a Builder/Relation method off a relation no longer collapses to `mixed` and a
 * call-site `@return <..., self>` still type-checks on a non-final model.
 *
 * Covers: final vs non-final declaring model, hasMany()->where() chain, and a child-model
 * relation where `static` resolves to the child (more precise than `self`, parallel to #1043).
 */
class Customer extends Model
{
}

class Book extends Model
{
}

// Intermediate model for the through-relation probe.
class Publisher extends Model
{
}

// NON-final: covariance is required here (the return statement infers Order&static, which only
// satisfies the declared @return <..., self> because TDeclaringModel is covariant).
class Order extends Model
{
    /** @return BelongsTo<Customer, self> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    /** @return HasMany<Book, self> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class)->where('published', true);
    }

    // Through-relation: the declaring model is the THIRD template arg here (after the related
    // and intermediate models), so this pins that covariance was applied to the correct slot.
    /** @return HasManyThrough<Book, Publisher, self> */
    public function booksThroughPublisher(): HasManyThrough
    {
        return $this->hasManyThrough(Book::class, Publisher::class)->where('published', true);
    }

    // Polymorphic relation (MorphTo extends BelongsTo): related defaults to the base Model and
    // the declaring model is templated as static, exercising the morph branch of the fix.
    /** @return MorphTo<Model, self> */
    public function imageable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }
}

// FINAL: static collapses to the final class, so even invariant TDeclaringModel would suffice;
// this pins that the static return param does not regress the final case.
final class Invoice extends Model
{
    /** @return BelongsTo<Customer, self> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }
}

// Child-model relation: the base declares `static` (not `self`), so a call on the child
// resolves the declaring model to the child (ChildOrder), which is strictly more precise than
// `self` would be (which would collapse to BaseOrder). ChildOrder is final so `static`
// resolves to exactly ChildOrder (a non-final child would carry the ChildOrder&static marker).
class BaseOrder extends Model
{
    /** @return BelongsTo<Customer, static> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

final class ChildOrder extends BaseOrder
{
}

function probe_non_final(Order $order): void
{
    $_customer = $order->customer();
    /** @psalm-check-type-exact $_customer = BelongsTo<Customer, Order> */

    $_books = $order->books();
    /** @psalm-check-type-exact $_books = HasMany<Book, Order> */

    $_through = $order->booksThroughPublisher();
    /** @psalm-check-type-exact $_through = HasManyThrough<Book, Publisher, Order> */

    $_imageable = $order->imageable();
    /** @psalm-check-type-exact $_imageable = MorphTo<Model, Order> */
}

function probe_final(Invoice $invoice): void
{
    $_customer = $invoice->customer();
    /** @psalm-check-type-exact $_customer = BelongsTo<Customer, Invoice> */
}

function probe_child(ChildOrder $child): void
{
    $_owner = $child->owner();
    /** @psalm-check-type-exact $_owner = BelongsTo<Customer, ChildOrder> */
}

?>
--EXPECTF--
