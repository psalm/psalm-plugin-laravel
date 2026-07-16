--FILE--
<?php declare(strict_types=1);

// Boundary tests: producer narrowing must never leak onto bare contract-typed values
// (parameters, factory results, custom implementations) — only the producer class, its
// canonical facade, and root aliases are registered, never the PasswordBroker /
// PasswordBrokerFactory contracts themselves.

function _bareContractBroker(\Illuminate\Contracts\Auth\PasswordBroker $broker, \Illuminate\Contracts\Auth\CanResetPassword $user): void {
    $broker->createToken($user);

    $_sendResetLink = $broker->sendResetLink([]);
    /** @psalm-check-type-exact $_sendResetLink = string */
}

function _bareFactoryBroker(\Illuminate\Contracts\Auth\PasswordBrokerFactory $factory, \Illuminate\Contracts\Auth\CanResetPassword $user): void {
    $_broker = $factory->broker();
    /** @psalm-check-type-exact $_broker = \Illuminate\Contracts\Auth\PasswordBroker */

    $_broker->createToken($user);
}

// A hand-rolled implementation of the contract is not the producer — its own concrete
// type must not expose createToken() either, proving we never widen based on interface
// conformance. Reported as UndefinedMethod (not UndefinedInterfaceMethod) because the
// param is typed by the concrete class here, not the interface.
final class CustomPasswordBroker implements \Illuminate\Contracts\Auth\PasswordBroker {
    #[\Override]
    public function sendResetLink(array $credentials, ?\Closure $callback = null): string {
        return 'sent';
    }

    #[\Override]
    public function reset(array $credentials, \Closure $callback): mixed {
        return true;
    }
}

function _customImplementationBoundary(CustomPasswordBroker $broker, \Illuminate\Contracts\Auth\CanResetPassword $user): void {
    $broker->createToken($user);
}

// Untouched pseudo-method guard: sendResetLink is not in the narrowed method list, so the
// facade's own @method pseudo-tag return type (string) still applies unmodified.
$_sendResetLink = \Illuminate\Support\Facades\Password::sendResetLink([]);
/** @psalm-check-type-exact $_sendResetLink = string */
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: %s
UndefinedInterfaceMethod on line %d: %s
UndefinedMethod on line %d: %s
