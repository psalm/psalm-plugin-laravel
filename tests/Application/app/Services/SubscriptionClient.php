<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Test fixture for {@see \App\Facades\Subscription}.
 *
 * The facade's accessor is the string alias `'subscription'`, bound to this
 * class by {@see \App\Providers\SubscriptionServiceProvider::register()}.
 * Reproduces the pattern documented in issue #942 (e.g.
 * `imdhemy/laravel-in-app-purchases` ships a `Subscription` facade backed by a
 * provider-bound alias).
 */
final class SubscriptionClient
{
    public function googlePlay(): self
    {
        return $this;
    }

    public function appStore(): self
    {
        return $this;
    }

    public function id(): int
    {
        return 0;
    }
}
