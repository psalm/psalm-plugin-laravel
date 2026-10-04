--FILE--
<?php declare(strict_types=1);

use App\Models\Shop;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1623
 *
 * Accepted soundness gap (ModelAggregateLoadHandler docblock): `refresh()` unsets the cached
 * aggregate fact, but a branch merge ignores a key missing from one side, so a refresh() inside
 * only ONE branch leaves the pre-branch proof in place afterwards. Same class of imprecision as
 * Psalm keeping property facts after impure calls.
 */
function test_refresh_in_one_branch_keeps_the_proof(Shop $shop, bool $flag): void
{
    $shop->loadCount('workOrders');
    if ($flag) {
        $shop->refresh();
    }

    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->work_orders_count;
    echo $count;
}
?>
--EXPECTF--
