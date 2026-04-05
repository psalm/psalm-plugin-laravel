--FILE--
<?php declare(strict_types=1);

use App\Models\User;

/**
 * Model::increment() and decrement() are protected in Laravel's source but
 * explicitly forwarded as public via Model::__call(). The plugin redeclares
 * them as public in the Model stub so external callers don't get errors.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/512
 */

function test_increment(User $user): void
{
    $_result = $user->increment('login_count');
    /** @psalm-check-type-exact $_result = int */
}

function test_decrement(User $user): void
{
    $_result = $user->decrement('login_count');
    /** @psalm-check-type-exact $_result = int */
}

function test_increment_with_amount(User $user): void
{
    $_result = $user->increment('login_count', 5);
    /** @psalm-check-type-exact $_result = int */
}

function test_increment_with_extra(User $user): void
{
    $_result = $user->increment('login_count', 1, ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = int */
}

function test_increment_quietly(User $user): void
{
    $_result = $user->incrementQuietly('login_count');
    /** @psalm-check-type-exact $_result = int */
}

function test_decrement_quietly(User $user): void
{
    $_result = $user->decrementQuietly('login_count');
    /** @psalm-check-type-exact $_result = int */
}

function test_increment_each(User $user): void
{
    $_result = $user->incrementEach(['login_count' => 1, 'view_count' => 5]);
    /** @psalm-check-type-exact $_result = int */
}

function test_decrement_each(User $user): void
{
    $_result = $user->decrementEach(['login_count' => 1]);
    /** @psalm-check-type-exact $_result = int */
}

function test_increment_each_with_extra(User $user): void
{
    $_result = $user->incrementEach(['login_count' => 1], ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = int */
}

function test_decrement_each_with_extra(User $user): void
{
    $_result = $user->decrementEach(['login_count' => 1], ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = int */
}
?>
--EXPECT--
