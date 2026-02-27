<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

/** @psalm-pure */
final class ApplicationInterfaceProvider
{
    /**
     * @return list<class-string>
     * @psalm-pure
     */
    public static function getApplicationInterfaceClassLikes(): array
    {
        return [
            \Illuminate\Contracts\Foundation\Application::class,
            \Illuminate\Contracts\Container\Container::class,
            \Illuminate\Foundation\Application::class,
        ];
    }
}
