--FILE--
<?php declare(strict_types=1);

namespace App\Tests;

use Illuminate\Foundation\Testing\TestCase;

/**
 * Negative-path guard for psalm/psalm-plugin-laravel#912.
 *
 * The fix must NOT hide user-declared properties on a TestCase subclass — only the four
 * Laravel-managed properties ($app, $callbackException, $traitsUsedByTest, $faker) should be
 * silently treated as initialized. User-declared properties with no default value and no
 * constructor assignment must still raise PropertyNotSetInConstructor.
 *
 * Tightens the contract from the issue's "property-level approach is preferable" preference:
 * if a future refactor accidentally regresses to a class-level suppression, this test fails.
 *
 * Visibility matters for the emitted error string: Psalm appends "private or final " to the
 * message when any uninitialized property is private. Both branches are exercised below to
 * guard against a regression that narrows by visibility.
 */
final class UnInitializedPrivatePropertyTest extends TestCase
{
    private int $privateField;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function it_uses_field(): int
    {
        return $this->privateField;
    }
}

final class UnInitializedProtectedPropertyTest extends TestCase
{
    protected int $protectedField;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function it_uses_field(): int
    {
        return $this->protectedField;
    }
}
?>
--EXPECTF--
PropertyNotSetInConstructor on line %d: Property App\Tests\UnInitializedPrivatePropertyTest::$privateField is not defined in constructor of App\Tests\UnInitializedPrivatePropertyTest or in any private or final methods called in the constructor
PropertyNotSetInConstructor on line %d: Property App\Tests\UnInitializedProtectedPropertyTest::$protectedField is not defined in constructor of App\Tests\UnInitializedProtectedPropertyTest or in any methods called in the constructor
