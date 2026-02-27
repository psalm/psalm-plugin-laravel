<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Psalm\LaravelPlugin\Handlers\Auth\Concerns\ExtractsGuardNameFromCallLike;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;

use function is_string;

/**
 * Handles cases:
 * @see \Illuminate\Http\Request::user()
 * @see \Illuminate\Http\Request::user('guard-name')
 */
final class RequestHandler implements MethodReturnTypeProviderInterface
{
    use ExtractsGuardNameFromCallLike;

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [\Illuminate\Http\Request::class];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        if ($event->getMethodNameLowercase() !== 'user') {
            return null;
        }

        $default_guard = AuthConfigAnalyzer::instance()->getDefaultGuard();
        if (! is_string($default_guard)) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        $guard = self::getGuardNameFromFirstArgument($event->getStmt(), $default_guard);
        if (! is_string($guard)) {
            return null;
        }

        $authenticatable_fqcn = AuthConfigAnalyzer::instance()->getAuthenticatableFQCN($guard);
        if (! is_string($authenticatable_fqcn)) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        return new Type\Union([
            new Type\Atomic\TNamedObject($authenticatable_fqcn),
            new Type\Atomic\TNull(),
        ]);
    }
}
