--FILE--
<?php declare(strict_types=1);

// `LARAVEL_START` is defined by an app's public/index.php and artisan, which Psalm never analyses.
$_start = \LARAVEL_START;
/** @psalm-check-type-exact $_start = float */

$_elapsed = \microtime(true) - \LARAVEL_START;
/** @psalm-check-type-exact $_elapsed = float */
?>
--EXPECTF--
