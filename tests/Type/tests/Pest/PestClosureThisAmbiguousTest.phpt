--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-pest.xml
--FILE--
<?php declare(strict_types=1);

namespace PestAmbiguousFixture {
    abstract class FirstCase extends \PHPUnit\Framework\TestCase
    {
    }

    abstract class SecondCase extends \PHPUnit\Framework\TestCase
    {
    }
}

namespace {
    // Two classes for one file makes Pest throw TestCaseAlreadyInUse; a dynamic argument
    // cannot be resolved statically. Both decline and keep Pest's own binding.
    uses(PestAmbiguousFixture\FirstCase::class);
    uses(PestAmbiguousFixture\SecondCase::class);

    test('keeps the TestCall binding on conflicting classes', function (): void {
        /** @psalm-check-type-exact $this = Pest\PendingCalls\TestCall */
        $this->group('x');
    });
}
?>
--EXPECTF--
