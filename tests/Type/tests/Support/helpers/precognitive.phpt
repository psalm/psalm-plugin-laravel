--FILE--
<?php declare(strict_types=1);

/** @psalm-suppress UnusedVariable */
$_payload = precognitive(function () {
    return ['foo' => 'bar'];
});
/** @psalm-check-type-exact $_payload = array{'foo': 'bar'} */
?>
--EXPECTF--
