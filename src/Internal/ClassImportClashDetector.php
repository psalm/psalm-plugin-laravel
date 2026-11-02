<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Storage\ClassLikeStorage;

/**
 * Detects a class declared under a short name its own file already imports for another class
 * (`use Illuminate\Support\Facades\Cache; class Cache extends Cache {}`). PHP rejects that file with
 * an uncatchable compile error, so autoloading the class ends the whole run (#2136); Psalm reports
 * the file as ParseError on its own.
 *
 * Mirrors Psalm's check in ClassLikeNodeScanner, but case-insensitive like PHP, so a legal import
 * of the class itself (`use app\CACHE; class Cache {}`) is not flagged.
 *
 * @internal
 * @psalm-immutable
 */
final class ClassImportClashDetector
{
    public static function clashes(ClassLikeStorage $storage): bool
    {
        $lastSeparator = \strrpos($storage->name, '\\');
        $shortName = $lastSeparator === false ? $storage->name : \substr($storage->name, $lastSeparator + 1);
        $imported = $storage->aliases?->uses[\strtolower($shortName)] ?? null;

        return $imported !== null && \strcasecmp($imported, $storage->name) !== 0;
    }
}
