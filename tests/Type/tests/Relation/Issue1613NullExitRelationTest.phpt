--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Relation bodies with a null exit (https://github.com/psalm/psalm-plugin-laravel/issues/1613).
 *
 * `if (...) { return; } return $this->hasOne(...)` (the pixelfed AccountInterstitial::status() shape)
 * stays a relation: the null exit only makes the method call nullable. Property access keeps the
 * relation's own type, since Laravel throws there instead of returning null. A delegation into such
 * a body declines: its chain would run on null.
 */

function issue1613_bare_return_guard_call(Shop $shop): ?HasOne
{
    $relation = $shop->guardedInvoice();
    /** @psalm-check-type-exact $relation = HasOne<Invoice, Shop>|null */
    return $relation;
}

function issue1613_bare_return_guard_property(Shop $shop): ?Invoice
{
    $invoice = $shop->guardedInvoice;
    /** @psalm-check-type-exact $invoice = Invoice|null */
    return $invoice;
}

function issue1613_null_return_guard_call(Shop $shop): ?BelongsTo
{
    $relation = $shop->guardedOwner();
    /** @psalm-check-type-exact $relation = BelongsTo<Customer, Shop>|null */
    return $relation;
}

function issue1613_null_return_guard_property(Shop $shop): ?Customer
{
    $owner = $shop->guardedOwner;
    /** @psalm-check-type-exact $owner = Customer|null */
    return $owner;
}

function issue1613_delegation_to_nullable_target_declines(Shop $shop): HasOne
{
    $relation = $shop->latestGuardedInvoice();
    /** @psalm-check-type-exact $relation = HasOne<Model, Model> */
    return $relation;
}
?>
--EXPECTF--
