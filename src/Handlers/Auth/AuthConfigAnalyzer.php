<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psalm\LaravelPlugin\Providers\ConfigRepositoryProvider;

use function is_array;
use function is_string;
use function array_keys;

final class AuthConfigAnalyzer
{
    private static ?AuthConfigAnalyzer $instance = null;

    private function __construct(private readonly ConfigRepository $config)
    {
    }

    public static function instance(): self
    {
        if (!self::$instance instanceof AuthConfigAnalyzer) {
            self::$instance = new AuthConfigAnalyzer(ConfigRepositoryProvider::get());
        }

        return self::$instance;
    }

    /**
     * @return class-string<\Illuminate\Contracts\Auth\Authenticatable>|null
     */
    public function getAuthenticatableFQCN(?string $guard = null): ?string
    {
        if ($guard === null) {
            $guard = $this->getDefaultGuard();

            if (! is_string($guard)) {
                return null;
            }
        }

        $provider = $this->config->get("auth.guards.{$guard}.provider");

        if (! is_string($provider)) {
            return null;
        }

        if ($this->config->get("auth.providers.{$provider}.driver") === 'database') {
            return \Illuminate\Auth\GenericUser::class;
        }

        /** @var class-string<\Illuminate\Contracts\Auth\Authenticatable>|null $model */
        $model = $this->config->get("auth.providers.{$provider}.model");

        return is_string($model) ? $model : null;
    }

    public function getDefaultGuard(): ?string
    {
        /** @var string|null $guard */
        $guard = $this->config->get('auth.defaults.guard');

        return is_string($guard) ? $guard : null;
    }

    /** @return list<class-string<\Illuminate\Contracts\Auth\Authenticatable>> */
    public function getAllAuthenticatables(): array
    {
        $all_authenticatables = [];

        $authGuards = $this->config->get('auth.guards');
        /** @var list<string> $guards */
        $guards = is_array($authGuards) ? array_keys($authGuards) : [];

        foreach ($guards as $guard) {
            $authenticatable_fqcn = $this->getAuthenticatableFQCN($guard);
            if (is_string($authenticatable_fqcn)) {
                $all_authenticatables[] = $authenticatable_fqcn;
            }
        }

        return $all_authenticatables;
    }
}
