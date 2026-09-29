--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-pest.xml
--FILE--
<?php declare(strict_types=1);

namespace PestDynamicFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
    }
}

namespace {
    /** @var class-string $class */
    $class = PestDynamicFixture\TestCase::class;
    // A non-literal class argument cannot be resolved statically: decline.
    uses($class);

    test('keeps the TestCall binding on a dynamic uses()', function (): void {
        /** @psalm-check-type-exact $this = Pest\PendingCalls\TestCall */
        $this->group('x');
    });
}
?>
--EXPECTF--
