--FILE--
<?php

use Illuminate\Database\Eloquent\Builder;

/**
 * @param Builder $query
 */
function test_lowercase_alias($query): void {
    lowercase($query)->where('id', 1);
}
?>
--EXPECTF--