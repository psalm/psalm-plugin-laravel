--FILE--
<?php declare(strict_types=1);
/** @return false */
function abort_if_filters_out_possible_types(bool $flag): bool {
    abort_if($flag, 422);
    return $flag;
}

/** @return true */
function abort_unless_filters_out_possible_types(bool $flag): bool {
    abort_unless($flag, 422);
    return $flag;
}
?>
--EXPECTF--
