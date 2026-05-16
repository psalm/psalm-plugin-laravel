--FILE--
<?php declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Negative-path guard for psalm/psalm-plugin-laravel#945.
 *
 * The fix must NOT hide user-declared properties on a ServiceProvider subclass — only the
 * framework-managed `$app` should be silently treated as initialized. User-declared properties
 * with no default value and no constructor assignment must still raise
 * `PropertyNotSetInConstructor`.
 *
 * If a future refactor accidentally regresses to a class-level suppression on ServiceProvider
 * subclasses, this test fails. Visibility matters for the emitted error string: Psalm appends
 * "private or final " to the message when any uninitialized property is private; both branches
 * are exercised to guard against a regression that narrows by visibility.
 */
final class UnInitializedPrivatePropertyProvider extends ServiceProvider
{
    private int $privateField;

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function value(): int
    {
        return $this->privateField;
    }
}

final class UnInitializedProtectedPropertyProvider extends ServiceProvider
{
    protected int $protectedField;

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function value(): int
    {
        return $this->protectedField;
    }
}
?>
--EXPECTF--
PropertyNotSetInConstructor on line %d: Property App\Providers\UnInitializedPrivatePropertyProvider::$privateField is not defined in constructor of App\Providers\UnInitializedPrivatePropertyProvider or in any private or final methods called in the constructor
PropertyNotSetInConstructor on line %d: Property App\Providers\UnInitializedProtectedPropertyProvider::$protectedField is not defined in constructor of App\Providers\UnInitializedProtectedPropertyProvider or in any methods called in the constructor
