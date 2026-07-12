--FILE--
<?php declare(strict_types=1);

// Password::broker() / PasswordBrokerManager::broker() are narrowed to the concrete
// PasswordBroker, so concrete-only methods (createToken, deleteToken, tokenExists,
// getRepository, getTimebox, getUser) resolve without errors.

$_broker = \Illuminate\Support\Facades\Password::broker();
/** @psalm-check-type-exact $_broker = \Illuminate\Auth\Passwords\PasswordBroker */

// Root alias — a separate class from the canonical facade FQCN in Psalm's eyes.
$_brokerAlias = \Password::broker();
/** @psalm-check-type-exact $_brokerAlias = \Illuminate\Auth\Passwords\PasswordBroker */

function _diManager(\Illuminate\Auth\Passwords\PasswordBrokerManager $manager): void {
    $_managerBroker = $manager->broker();
    /** @psalm-check-type-exact $_managerBroker = \Illuminate\Auth\Passwords\PasswordBroker */

    $_managerNamedBroker = $manager->broker('users');
    /** @psalm-check-type-exact $_managerNamedBroker = \Illuminate\Auth\Passwords\PasswordBroker */
}

function _concreteOnlyMethods(\Illuminate\Contracts\Auth\CanResetPassword $user): void {
    $_token = \Illuminate\Support\Facades\Password::broker()->createToken($user);
    /** @psalm-check-type-exact $_token = string */

    \Illuminate\Support\Facades\Password::broker()->deleteToken($user);

    $_exists = \Illuminate\Support\Facades\Password::broker()->tokenExists($user, 'some-token');
    /** @psalm-check-type-exact $_exists = bool */

    $_repository = \Illuminate\Support\Facades\Password::broker()->getRepository();
    /** @psalm-check-type-exact $_repository = \Illuminate\Auth\Passwords\TokenRepositoryInterface */

    $_timebox = \Illuminate\Support\Facades\Password::broker()->getTimebox();
    /** @psalm-check-type-exact $_timebox = \Illuminate\Support\Timebox */

    $_foundUser = \Illuminate\Support\Facades\Password::broker()->getUser([]);
    /** @psalm-check-type-exact $_foundUser = \Illuminate\Contracts\Auth\CanResetPassword|null */
}
?>
--EXPECTF--
