--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-pest.xml
--FILE--
<?php declare(strict_types=1);

namespace PestInFileUsesFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        protected string $token = '';

        protected function login(): int
        {
            return 1;
        }
    }
}

namespace {
    // In-file uses() without in() targets this file only; Pest binds the closures to it.
    uses(PestInFileUsesFixture\TestCase::class);

    test('binds $this to the in-file TestCase', function (): void {
        /** @psalm-check-type-exact $this = PestInFileUsesFixture\TestCase */
        $_token = $this->token;
        /** @psalm-check-type-exact $_token = string */
        $_id = $this->login();
        /** @psalm-check-type-exact $_id = int */
        $this->assertTrue(true);
    });

    it('binds it() too', function (): void {
        /** @psalm-check-type-exact $this = PestInFileUsesFixture\TestCase */
        $this->assertTrue(true);
    });

    beforeEach(function (): void {
        /** @psalm-check-type-exact $this = PestInFileUsesFixture\TestCase */
        $this->token = 'set';
    });

    afterEach(function (): void {
        /** @psalm-check-type-exact $this = PestInFileUsesFixture\TestCase */
        $this->token = '';
    });

    // A closure nested in a bound one inherits the bound class as its scope.
    test('nested closures keep the binding', function (): void {
        (function (): void {
            /** @psalm-check-type-exact $this = PestInFileUsesFixture\TestCase&static */
            $this->assertTrue(true);
        })();
    });

    test('arrow functions are bound too', fn () => $this->assertTrue(true));
}
?>
--EXPECTF--
