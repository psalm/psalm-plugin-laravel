--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

/**
 * Pins the accepted limitation documented on AbstractPaginator::setCollection() in
 * stubs/common/Pagination/Pagination.phpstub: a chained call keeps the receiver's old templates,
 * while the assigned form rebinds them through `@psalm-this-out`.
 */
function chained_set_collection_keeps_old_templates(): void
{
    $paginator = new CursorPaginator(['old' => 1], 10);
    $_chained = $paginator->setCollection(new Collection(['new' => 'a']))->items();
    /** @psalm-check-type-exact $_chained = array<'old'|int, 1> */
}

function assigned_set_collection_rebinds_templates(): void
{
    $paginator = new CursorPaginator(['old' => 1], 10);
    $paginator->setCollection(new Collection(['new' => 'a']));
    $_assigned = $paginator->items();
    /** @psalm-check-type-exact $_assigned = array<'new'|int, 'a'> */
}
--EXPECTF--
