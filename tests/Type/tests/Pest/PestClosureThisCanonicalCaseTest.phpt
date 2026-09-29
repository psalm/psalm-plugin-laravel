--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-pest.xml
--FILE--
<?php declare(strict_types=1);

namespace PestCanonicalCaseFixture {
    abstract class MixedCase extends \PHPUnit\Framework\TestCase
    {
    }
}

namespace {
    // PHP class names are case-insensitive; the bound type (and its messages) use the declared spelling.
    uses(pestcanonicalcasefixture\mixedcase::class);

    test('binds the declared class name', function (): void {
        /** @psalm-check-type-exact $this = PestCanonicalCaseFixture\MixedCase */
        $this->assertTrue(true);
        $this->missing = 1;
    });
}
?>
--EXPECTF--
InvalidClass on line %d: Class, interface or enum pestcanonicalcasefixture\mixedcase has wrong casing
UndefinedThisPropertyAssignment on line %d: Instance property PestCanonicalCaseFixture\MixedCase::$missing is not defined
