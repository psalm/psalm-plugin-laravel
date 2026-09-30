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
     * @return null|string null on success, otherwise a human-readable failure reason.
     *                     No temporary file is left behind in either case.
     */
    public static function write(string $path, string $contents): ?string
    {
        // The temp file must live in the target's directory: rename() is only atomic within
        // one filesystem. The uniqid() suffix keeps concurrent writers apart even when they
        // share a pid (separate containers mounting the same volume all run as pid 1).
        $pid = \getmypid();
        $tmpPath = \sprintf('%s.tmp.%d.%s', $path, $pid === false ? 0 : $pid, \uniqid('', true));

        \error_clear_last();

        if (@\file_put_contents($tmpPath, $contents) !== \strlen($contents)) {
            // Read the error before unlink(), which would overwrite it.
            $reason = "cannot write temp file '{$tmpPath}'" . self::lastErrorSuffix();
            @\unlink($tmpPath);

            return $reason;
        }

        if (!@\rename($tmpPath, $path)) {
            $reason = "cannot rename temp file to '{$path}'" . self::lastErrorSuffix();
            @\unlink($tmpPath);

            return $reason;
        }

        return null;
    }

    private static function lastErrorSuffix(): string
    {
        $error = \error_get_last();

        return $error !== null ? ": {$error['message']}" : '';
    }
}
