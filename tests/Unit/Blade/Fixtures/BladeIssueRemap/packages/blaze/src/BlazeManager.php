<?php

declare(strict_types=1);

namespace Livewire\Blaze;

/** The members of livewire/blaze's manager the plugin and the fixture's precompiler touch. */
class BlazeManager
{
    protected ?bool $enabled = null;

    protected ?bool $debug = null;

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled ??= (bool) config('blaze.enabled', true);
    }

    public function isDebugging(): bool
    {
        return $this->debug ??= (bool) config('blaze.debug', false);
    }
}
