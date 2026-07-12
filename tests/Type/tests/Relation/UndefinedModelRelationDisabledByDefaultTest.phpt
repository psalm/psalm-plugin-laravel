--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

/**
 * The handler is always registered. Under the default configuration (no
 * <experimental /> element), its default reporting level is advisory info.
 * See https://github.com/psalm/psalm-plugin-laravel/issues/643
 */
Customer::with('nonExistentRelation');
Customer::query()->whereHas('typoRelation');
?>
--EXPECTF--
