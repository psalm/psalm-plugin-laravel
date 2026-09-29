--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-pest.xml
--FILE--
<?php declare(strict_types=1);

// Pest marks its DSL classes @internal, yet expect()->toX(), uses()->in() and pest()->extend()
// are the documented way to write tests: no InternalMethod on those.
expect(true)->toBeTrue();
uses()->in('Feature');
pest()->extend(PHPUnit\Framework\TestCase::class)->in('Unit');
test('x')->group('g');

// A genuinely internal Pest class keeps reporting.
(new Pest\Kernel())->boot();
?>
--EXPECTF--
InternalMethod on line %d: The method Pest\Kernel::boot is internal to Pest but called from root namespace
