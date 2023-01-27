<?php

namespace Psalm\LaravelPlugin\Providers;

final class ApplicationInterfaceProvider
{
    /** @return list<class-string> */
    public static function getApplicationInterfaceClassLikes(): array
    {
        return [
            \Illuminate\Contracts\Foundation\Application::class,
            \Illuminate\Contracts\Container\Container::class,
            \Illuminate\Foundation\Application::class,
        ];
    }
}
