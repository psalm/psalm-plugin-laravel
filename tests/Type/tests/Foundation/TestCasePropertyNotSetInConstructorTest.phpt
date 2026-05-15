--FILE--
<?php declare(strict_types=1);

namespace App\Tests;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Foundation\Testing\WithFaker;

/**
 * Regression guard for psalm/psalm-plugin-laravel#912.
 *
 * Subclasses of `Illuminate\Foundation\Testing\TestCase` should NOT trigger
 * `PropertyNotSetInConstructor` for the framework-managed properties Laravel populates through
 * its testing lifecycle (setUp() / createApplication() / setUpFaker()):
 *
 *   - $app                — declared in the `InteractsWithTestCaseLifecycle` trait
 *   - $callbackException  — declared in the `InteractsWithTestCaseLifecycle` trait
 *   - $traitsUsedByTest   — declared on TestCase itself, assigned in createApplication()
 *   - $faker              — declared on the `WithFaker` trait, assigned in setUpFaker()
 *
 * The fix marks these properties as initialized on their actual declaring class storage
 * (trait or parent) so the un-init check skips them for every subclass without hiding genuine
 * un-initialized properties the user might declare.
 */
abstract class ApplicationTestCase extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
    }
}

final class DirectSubclassTest extends ApplicationTestCase
{
    public function it_does_something(): void
    {
        // ...
    }
}

/**
 * Multi-level inheritance check. Mid sits between the abstract base and the leaf test class,
 * so a regression that only walks one level of the parent chain (e.g., looking at
 * `parent_classes[0]` instead of writing to the declaring storage) would fail this case.
 */
abstract class IntermediateTestCase extends ApplicationTestCase
{
    protected function helper(): int
    {
        return 1;
    }
}

final class DeeplyNestedSubclassTest extends IntermediateTestCase
{
    public function it_extends_deeper(): int
    {
        return $this->helper();
    }
}

final class WithFakerSubclassTest extends ApplicationTestCase
{
    use WithFaker;

    public function it_uses_faker(): void
    {
        // ...
    }
}
?>
--EXPECTF--
