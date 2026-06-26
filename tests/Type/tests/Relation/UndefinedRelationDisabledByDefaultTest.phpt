--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

/**
 * The rule is opt-in. Under the default config (no <findUndefinedRelations />),
 * a typo'd relation name must NOT be reported. Empty EXPECTF asserts silence.
 * See https://github.com/psalm/psalm-plugin-laravel/issues/643
 */
Customer::with('nonExistentRelation');
Customer::query()->whereHas('typoRelation');
?>
--EXPECTF--
