--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

// The facade's generated `@method static array getColumnListing(...)` is wider than
// Schema\Builder::getColumnListing(), which returns list<string>.

/** @return list<string> */
function columns(): array
{
    /** @psalm-check-type-exact $columns = list<string> */
    $columns = Schema::getColumnListing('users');

    return $columns;
}
?>
--EXPECTF--
