<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Config;

/**
 * Which translation keys `findMissingTranslations` reports as missing.
 *
 * Only the MissingTranslation emission is gated: type narrowing of `__()` / `trans()`
 * results applies to every key regardless of this setting.
 *
 * @internal
 */
enum TranslationKeys: string
{
    /** Report every literal key that is not found, including `__('Some sentence.')` JSON-style keys. */
    case All = 'all';

    /** Report only Laravel "short keys" (`group.key`), skipping JSON-style sentence keys. */
    case Short = 'short';

    /**
     * A short key has at least two non-empty dot-separated segments and no whitespace.
     * That excludes sentences (`Done.`, `e.g.`, `Online courses - more coming up!`) and bare
     * words (`Dashboard`), which are JSON keys whose fallback is the key itself.
     *
     * @psalm-mutation-free
     */
    public function shouldReport(string $translationKey): bool
    {
        return $this === self::All
            || \preg_match('/^[^\s.]+(\.[^\s.]+)+$/u', $translationKey) === 1;
    }
}
