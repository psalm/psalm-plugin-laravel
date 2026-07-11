<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

/**
 * An existing source directory with separate filesystem and presentation paths.
 *
 * @internal
 */
final readonly class SourceRootCandidate
{
    /** @psalm-mutation-free */
    private function __construct(
        public string $cleanPath,
        public string $canonicalPath,
    ) {}

    /**
     * Resolve the path through the filesystem before deriving its display form.
     * This deliberately leaves `..` intact until realpath() has traversed any
     * preceding symlink, because lexical normalisation can select another directory.
     *
     * @psalm-impure Filesystem state determines whether and where the path resolves.
     */
    public static function resolve(string $projectRoot, string $path): ?self
    {
        $absolutePath = self::isAbsolute($path)
            ? $path
            : $projectRoot . \DIRECTORY_SEPARATOR . ($path === '' ? '.' : $path);
        $resolvedPath = \realpath($absolutePath);
        if ($resolvedPath === false || !\is_dir($resolvedPath)) {
            return null;
        }

        // realpath() resolves symlinks and dot segments but can retain caller casing
        // on case-insensitive filesystems. Re-read directory entries so the path is
        // also stable for exact comparisons without relying on non-portable inode keys.
        $canonicalPath = self::canonicalizeCase($resolvedPath);
        $canonicalProjectRoot = self::canonicalizeCase($projectRoot);
        if ($canonicalPath === $canonicalProjectRoot) {
            return new self('.', $canonicalPath);
        }

        $projectPrefix = \rtrim($canonicalProjectRoot, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
        if (\str_starts_with($canonicalPath, $projectPrefix)) {
            $relativePath = \substr($canonicalPath, \strlen($projectPrefix));

            return new self(\str_replace(\DIRECTORY_SEPARATOR, '/', $relativePath), $canonicalPath);
        }

        return new self($canonicalPath, $canonicalPath);
    }

    /**
     * True when both candidates resolve to the same filesystem directory.
     *
     * @psalm-mutation-free
     */
    public function isSame(self $other): bool
    {
        return $this->canonicalPath === $other->canonicalPath;
    }

    /**
     * True when this directory is the supplied directory or one of its descendants.
     *
     * @psalm-mutation-free
     */
    public function isWithin(self $directory): bool
    {
        if ($this->isSame($directory)) {
            return true;
        }

        $prefix = \rtrim($directory->canonicalPath, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;

        return \str_starts_with($this->canonicalPath, $prefix);
    }

    /** @psalm-pure */
    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return $path[0] === '/'
            || (\DIRECTORY_SEPARATOR === '\\'
                && ($path[0] === '\\' || \preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1));
    }

    /** Rebuild an absolute path with the casing stored in each parent directory. */
    private static function canonicalizeCase(string $path): string
    {
        $segments = [];
        $current = $path;
        while (true) {
            $parent = \dirname($current);
            if ($parent === $current) {
                break;
            }

            $name = \basename($current);
            $entries = @\scandir($parent);
            if ($entries !== false && !\in_array($name, $entries, true)) {
                $targetMetadata = @\stat($current);
                foreach ($entries as $entry) {
                    $entryMetadata = @\stat(
                        \rtrim($parent, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . $entry,
                    );
                    // Exact names always win above. Identity is only a fallback
                    // among siblings, where it recovers the stored spelling for
                    // non-ASCII aliases without becoming a global inode key.
                    if ($targetMetadata !== false
                        && $entryMetadata !== false
                        && $entryMetadata['dev'] === $targetMetadata['dev']
                        && $entryMetadata['ino'] === $targetMetadata['ino']
                    ) {
                        $name = $entry;
                        break;
                    }
                }
            }

            $segments[] = $name;
            $current = $parent;
        }

        foreach (\array_reverse($segments) as $segment) {
            $current = \rtrim($current, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . $segment;
        }

        return $current;
    }
}
