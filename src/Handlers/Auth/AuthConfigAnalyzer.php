<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psalm\LaravelPlugin\Providers\ConfigRepositoryProvider;

final class AuthConfigAnalyzer
{
    private static ?AuthConfigAnalyzer $instance = null;

    /** @psalm-mutation-free */
    private function __construct(private readonly ConfigRepository $config) {}

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

            if (! \is_string($guard)) {
                return null;
            }
        }

        $provider = $this->config->get("auth.guards.{$guard}.provider");

        if (! \is_string($provider)) {
            return null;
        }

        if ($this->config->get("auth.providers.{$provider}.driver") === 'database') {
            return \Illuminate\Auth\GenericUser::class;
        }

        /** @var class-string<\Illuminate\Contracts\Auth\Authenticatable>|null $model */
        $model = $this->config->get("auth.providers.{$provider}.model");

        return \is_string($model) ? $model : null;
    }

    public function getDefaultGuard(): ?string
    {
        /** @var string|null $guard */
        $guard = $this->config->get('auth.defaults.guard');

        return \is_string($guard) ? $guard : null;
    }

    /**
     * Maps a guard name to its concrete guard class based on the configured driver.
     * Returns null for unknown guards or non-standard (custom) drivers.
     *
     * Standard driver → class mappings (built into Laravel's AuthManager):
     * - 'session' → SessionGuard
     * - 'token'   → TokenGuard
     *
     * @return class-string<\Illuminate\Contracts\Auth\Guard>|null
     */
    public function getGuardFQCN(string $guard): ?string
    {
        $driver = $this->config->get("auth.guards.{$guard}.driver");

        if (! \is_string($driver)) {
            return null;
        }

        return match ($driver) {
            'session' => \Illuminate\Auth\SessionGuard::class,
            'token' => \Illuminate\Auth\TokenGuard::class,
            default => null, // custom drivers cannot be statically resolved
        };
    }

    /** @return list<class-string<\Illuminate\Contracts\Auth\Authenticatable>> */
    public function getAllAuthenticatables(): array
    {
        $all_authenticatables = [];

        /** @var array<string, mixed>|null $authGuards */
        $authGuards = $this->config->get('auth.guards');
        $guards = \is_array($authGuards) ? \array_keys($authGuards) : [];

        foreach ($guards as $guard) {
            $authenticatable_fqcn = $this->getAuthenticatableFQCN($guard);
            if (\is_string($authenticatable_fqcn)) {
                $all_authenticatables[] = $authenticatable_fqcn;
            }
        }

        return $all_authenticatables;
    }
}
