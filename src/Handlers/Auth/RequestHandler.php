<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Illuminate\Http\Request;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Scalar\String_;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Auth\Concerns\ExtractsGuardNameFromCallLike;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;

/**
 * Handles cases:
 * @see \Illuminate\Http\Request::user()
 * @see \Illuminate\Http\Request::user('guard-name')
 * @see \Illuminate\Http\Request::user() overridden in a subclass, e.g. Laravel Nova's NovaRequest
 */
final class RequestHandler implements MethodReturnTypeProviderInterface, AfterCodebasePopulatedInterface
{
    use ExtractsGuardNameFromCallLike;

    /**
     * Request subclasses that already carry the override closure, so a repeated population does
     * not stack closures. Psalm builds a fresh return-type provider per Codebase, hence {@see reset()}.
     *
     * @var array<lowercase-string, true>
     */
    private static array $registered = [];

    public static function reset(): void
    {
        self::$registered = [];
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Request::class];
    }

    /**
     * Psalm dispatches a method return-type provider only for the called class or the class
     * declaring the method, never for an intermediate ancestor. A subclass overriding `user()`
     * (Nova's `NovaRequest::user($guard = null)`) therefore never reaches the `Request`
     * registration and its calls stay `mixed`. Register the same closure on every Request
     * subclass whose `user()` resolves to an override (own, inherited from an overriding
     * parent, or imported from a trait); {@see overrideResolvesToMixed()} decides per call
     * whether the override is typed.
     */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $request = \strtolower(Request::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            $key = \strtolower($storage->name);
            $declaring = $storage->declaring_method_ids['user'] ?? null;

            if (isset(self::$registered[$key])
                || !isset($storage->parent_classes[$request])
                || $declaring === null
                || \strtolower($declaring->fq_class_name) === $request
            ) {
                continue;
            }

            self::$registered[$key] = true;
            $codebase->methods->return_type_provider->registerClosure($storage->name, self::getMethodReturnType(...));
        }
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        if ($event->getMethodNameLowercase() !== 'user') {
            return null;
        }

        // An override is bet to forward an explicit guard to `parent::user($guard)`, but it may
        // pick its own default (Nova reads `nova.guard`), so only an explicit literal or enum
        // case guard narrows there; no-arg and `null` decline.
        $is_override = \strtolower($event->getFqClasslikeName()) !== \strtolower(Request::class);
        if ($is_override) {
            $first_arg = $event->getStmt()->getArgs()[0] ?? null;
            if ($first_arg === null
                || $first_arg->unpack
                || !$first_arg->value instanceof String_ && !$first_arg->value instanceof ClassConstFetch
            ) {
                return null;
            }
        }

        $default_guard = AuthConfigAnalyzer::instance()->getDefaultGuard();
        if (!\is_string($default_guard)) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        $guard = self::getGuardNameFromFirstArgument($event->getStmt(), $default_guard, $event->getSource());
        if (!\is_string($guard)) {
            return null;
        }

        $authenticatable_fqcn = AuthConfigAnalyzer::instance()->getAuthenticatableFQCN($guard);
        if (!\is_string($authenticatable_fqcn)) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        // The app's own return type on an override wins over the guard's provider model.
        if ($is_override && !self::overrideResolvesToMixed($event)) {
            return null;
        }

        return new Type\Union([
            new Type\Atomic\TNamedObject($authenticatable_fqcn),
            new Type\Atomic\TNull(),
        ]);
    }

    /**
     * Asks Psalm for its own return type of the called class's `user()`, through the same call
     * `MethodCallReturnTypeFetcher` makes, minus template lower bounds (an unresolved template param
     * is never mixed, so this can only decline). The statements analyzer is load-bearing: without it,
     * `Methods::getMethodReturnType()` returns the documenting ancestor's type (`Request::user()`'s
     * `mixed`) over the override's own `?Admin` instead of reconciling the two.
     */
    private static function overrideResolvesToMixed(MethodReturnTypeProviderEvent $event): bool
    {
        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return false;
        }

        $codebase = $source->getCodebase();
        $self_class = null;
        $return_type = $codebase->methods->getMethodReturnType(
            $codebase,
            new MethodIdentifier(\strtolower($event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName()), 'user'),
            $self_class,
            $source,
            $event->getCallArgs(),
        );

        return !$return_type instanceof Type\Union || $return_type->isMixed();
    }
}
