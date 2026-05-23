<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Sentinel ConfigRepository used by {@see ConfigKeyResolver::instance()} when
 * the booted Laravel app cannot supply a real Repository (binding unbound,
 * partial bootstrap, exploding service provider during plugin init).
 *
 * Every method throws so {@see ConfigKeyResolver::warm()}'s catch arm fires
 * and caches `Type::getMixed()` for the key — the stub's pre-PR ceiling.
 * Without this sentinel, the throw would propagate from a Psalm hook handler
 * and crash analysis on every `config()` / `Repository::get()` call site.
 *
 * @internal
 */
final class ThrowingConfigRepository implements ConfigRepository
{
    private const MESSAGE = 'ConfigRepository unavailable: plugin booted without a usable Laravel app.';

    /** @psalm-pure */
    #[\Override]
    public function has($key): bool
    {
        throw new \LogicException(self::MESSAGE);
    }

    /** @psalm-pure */
    #[\Override]
    public function get($key, $default = null): mixed
    {
        throw new \LogicException(self::MESSAGE);
    }

    /**
     * @return array<string, mixed>
     * @psalm-pure
     */
    #[\Override]
    public function all(): array
    {
        throw new \LogicException(self::MESSAGE);
    }

    /** @psalm-pure */
    #[\Override]
    public function set($key, $value = null): void
    {
        throw new \LogicException(self::MESSAGE);
    }

    /** @psalm-pure */
    #[\Override]
    public function prepend($key, $value): void
    {
        throw new \LogicException(self::MESSAGE);
    }

    /** @psalm-pure */
    #[\Override]
    public function push($key, $value): void
    {
        throw new \LogicException(self::MESSAGE);
    }
}
