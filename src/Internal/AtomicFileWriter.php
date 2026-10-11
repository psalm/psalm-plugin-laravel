<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * Replaces a file's contents so that concurrent readers see either the old or the new
 * contents, never a partially written file.
 *
 * @internal
 */
final class AtomicFileWriter
{
    /**
     * @return null|string null on success, otherwise the PHP error behind the failure.
     *                     No temporary file is left behind in either case.
     */
    public static function write(string $path, string $contents): ?string
    {
        // Same directory as the target: rename() is only atomic within one filesystem.
        $tmpPath = $path . '.tmp.' . \bin2hex(\random_bytes(8));

        \error_clear_last();

        if (@\file_put_contents($tmpPath, $contents) === \strlen($contents) && @\rename($tmpPath, $path)) {
            return null;
        }

        // Read the error before unlink(), which would overwrite it.
        $error = \error_get_last();
        @\unlink($tmpPath);

        return $error['message'] ?? 'unknown error';
    }
}
