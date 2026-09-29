--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-pest.xml
--FILE--
<?php declare(strict_types=1);

namespace PestExtendFixture {
    trait InteractsWithFoo
    {
    }

    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        protected int $counter = 0;
    }
}

namespace {
    // pest()->extend() in a test file targets that file; traits are not bindable and are skipped.
    pest()->extend(PestExtendFixture\TestCase::class, PestExtendFixture\InteractsWithFoo::class);

    test('binds $this to the pest()->extend() class', function (): void {
        /** @psalm-check-type-exact $this = PestExtendFixture\TestCase */
        $_counter = $this->counter;
        /** @psalm-check-type-exact $_counter = int */
    });
}
?>
--EXPECTF--
