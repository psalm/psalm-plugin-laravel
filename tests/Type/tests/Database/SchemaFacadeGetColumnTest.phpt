--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.34.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

// Schema\Builder::getColumn() (Laravel 13.34+) returns `[]` for an absent column but documents only the
// full column shape, so `=== []` is a DocblockTypeContradiction. stubs/13.34.0 adds `|array{}`.

function schema_facade_get_column_may_be_empty(): bool
{
    return Schema::getColumn('users', 'missing') === [];
}
?>
--EXPECTF--
