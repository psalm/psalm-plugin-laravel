--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-pest.xml
--FILE--
<?php declare(strict_types=1);

namespace PestUntouchedFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
    }

    final class Box
    {
        public int $size = 1;
    }

    /** @param-closure-this Box $closure */
    function test(string $description, \Closure $closure): void
    {
        $closure->call(new Box(), $description);
    }

    // A namespaced test() is not Pest's global function: its own binding stays.
    test('own binding', function (): void {
        /** @psalm-check-type-exact $this = Box */
        $_size = $this->size;
    });
}

namespace {
    uses(PestUntouchedFixture\TestCase::class);

    // Static closures are never rebound (Pest rejects them at runtime).
    test('static closure', static function (): void {
    });
}
?>
--EXPECTF--
