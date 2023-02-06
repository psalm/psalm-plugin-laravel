<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Psalm\LaravelPlugin\Providers\ConfigRepositoryProvider;
use function is_string;

final class AuthConfigHelper
{
    /**
     * @psalm-suppress MoreSpecificReturnType
     * @return class-string<\Illuminate\Contracts\Auth\Authenticatable&\Illuminate\Database\Eloquent\Model>|null
     */
    public static function getAuthModel(string $guard): ?string
    {
        $config = ConfigRepositoryProvider::get();

        if ($guard === 'default') {
            /** @psalm-suppress MixedAssignment */
            $guard = $config->get('auth.defaults.guard');
            if (! is_string($guard)) {
                return null;
            }
        }

        $provider = $config->get("auth.guards.$guard.provider");
        if (! is_string($provider) || $provider === '') {
            return null;
        }

        if ($config->get("auth.providers.$provider.driver") !== 'eloquent') {
            return null;
        }

        /** @psalm-suppress MixedAssignment */
        $model_fqcn = $config->get("auth.providers.$provider.model");
        if (is_string($model_fqcn)) {
            /** @psalm-suppress LessSpecificReturnStatement */
            return $model_fqcn;
        }

        return null;
    }
}
