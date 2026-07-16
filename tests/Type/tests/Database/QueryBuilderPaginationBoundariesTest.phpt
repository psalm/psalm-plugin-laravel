--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * Guard: a bare Contracts\Pagination-typed value (parameter, not the
 * Query\Builder producer) is never narrowed — it exposes only the contract,
 * so a concrete-only method still raises UndefinedInterfaceMethod.
 */
function on_contract_receiver(LengthAwarePaginator $paginator): void
{
    $paginator->linkCollection();
}

function on_contract_paginator_receiver(Paginator $paginator): void
{
    $paginator->hasMorePagesWhen(true);
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Pagination\LengthAwarePaginator::linkCollection does not exist
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Pagination\Paginator::hasMorePagesWhen does not exist
