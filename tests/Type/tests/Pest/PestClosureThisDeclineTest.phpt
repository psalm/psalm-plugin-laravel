--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-pest.xml
--FILE--
<?php declare(strict_types=1);

// No tests/Pest.php next to the Psalm config and no in-file uses(): the TestCase is unknown,
// so Pest's own `@param-closure-this TestCall` binding stays untouched.
test('keeps the TestCall binding', function (): void {
    /** @psalm-check-type-exact $this = Pest\PendingCalls\TestCall */
    $this->group('x');
});
?>
--EXPECTF--
