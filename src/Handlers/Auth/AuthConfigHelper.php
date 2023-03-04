<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use function is_string;

final class AuthConfigHelper
{
    /**
     * @psalm-suppress MoreSpecificReturnType
     * @return class-string<\Illuminate\Contracts\Auth\Authenticatable>|null
     */
    public static function getAuthModel(ConfigRepository $config, ?string $guard = null): ?string
    {
        if ($guard === null) {
            /** @psalm-suppress MixedAssignment */
            $guard = $config->get('auth.defaults.guard');

            if (! is_string($guard)) {
                return null;
            }
        }

        /** @psalm-suppress MixedAssignment */
        $provider = $config->get("auth.guards.$guard.provider");

        if (! is_string($provider)) {
            return null;
        }

        if ($provider === 'database') {
            return '\Illuminate\Auth\GenericUser';
        }

        return $config->get("auth.providers.$provider.model", null);
    }
}
