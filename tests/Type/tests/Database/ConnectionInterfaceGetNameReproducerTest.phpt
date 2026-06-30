--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reproducer (documented broken behavior): `$query->getConnection()->getName()`.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/974
 *
 * From invoiceninja's app/Console/Commands/Elastic/ImportElasticSearchableModels.php:
 *
 *   $sqlConnection = $query->getConnection()->getName();
 *
 * `Builder::getConnection()` returns `ConnectionInterface`. The interface in
 * `Illuminate\Database\ConnectionInterface` does not declare `getName()`, even
 * though every concrete connection (`Illuminate\Database\Connection`) implements
 * it. So Psalm correctly reports `UndefinedInterfaceMethod`.
 *
 * This is an upstream Laravel gap (the contract is incomplete). The plugin can
 * either:
 *   1. Add `getName()` to the `ConnectionInterface` stub (forward-fix), or
 *   2. Wait for laravel/framework to add it on the contract.
 *
 * Logged here so the test moves to a positive regression check once a fix lands.
 */
function test_get_name_on_connection_interface(ConnectionInterface $conn): void
{
    $conn->getName();
}

function test_get_name_after_builder_get_connection(Builder $query): void
{
    $query->getConnection()->getName();
}

?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Database\ConnectionInterface::getName does not exist
UndefinedInterfaceMethod on line %d: Method Illuminate\Database\ConnectionInterface::getName does not exist
