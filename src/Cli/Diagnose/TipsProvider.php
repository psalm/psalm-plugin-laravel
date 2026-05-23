<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

/**
 * Collects non-fatal, environment-driven hints that can improve the user's
 * Psalm experience (faster cache serialization, JIT, etc.).
 *
 * Kept separate from {@see Diagnostics} so each tip is small, easy to test,
 * and easy to extend with new checks without bloating the main collector.
 *
 * @internal
 * @psalm-immutable
 */
class TipsProvider
{
    /**
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    public function collect(): array
    {
        $tips = [];

        $tip = $this->igbinaryTip();
        if ($tip !== null) {
            $tips[] = $tip;
        }

        return $tips;
    }

    /**
     * Psalm uses ext-igbinary for cache serialization when it is loaded and the version is >= 2.0.5
     * (otherwise it falls back to PHP's built-in serializer, which is slower).
     * Suggest installing/upgrading when missing or too old.
     *
     * @psalm-pure
     */
    protected function igbinaryTip(): ?string
    {
        if (!\extension_loaded('igbinary')) {
            return 'Install ext-igbinary (>=2.0.5) for faster Psalm cache serialization.';
        }

        $version = \phpversion('igbinary');
        if (\is_string($version) && \version_compare($version, '2.0.5', '<')) {
            return \sprintf(
                'Upgrade ext-igbinary to >=2.0.5 (installed: %s) for faster Psalm cache serialization.',
                $version,
            );
        }

        return null;
    }
}
