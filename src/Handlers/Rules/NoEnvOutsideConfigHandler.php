<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\NoEnvOutsideConfig;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Progress\Progress;
use Psalm\Type\Union;

/**
 * Reports env() calls outside the project's config directories.
 *
 * When config is cached (php artisan config:cache), the .env file is not loaded,
 * so env() returns null outside config files. Test files are excluded because
 * they run without config caching.
 *
 * The set of "config directories" is configured via `<configDirectory name="..." />`
 * elements in psalm.xml; when none are provided, the booted Laravel app's
 * `config_path()` is used. Glob patterns are supported (e.g. `app/<wildcard>/config`,
 * where the wildcard is `*`) and resolved at plugin boot. Runtime checks are a pure
 * str_starts_with loop.
 *
 * @see https://laravel.com/docs/configuration#configuration-caching
 */
final class NoEnvOutsideConfigHandler implements FunctionReturnTypeProviderInterface
{
    /**
     * Resolved absolute config directory paths, each with a trailing DIRECTORY_SEPARATOR.
     * Stored once at boot via init() so the runtime check is a pure str_starts_with loop.
     *
     * @var list<string>
     */
    private static array $configDirectories = [];

    /**
     * Resolve user-provided config directory paths into absolute, glob-expanded paths.
     *
     * Each entry may be:
     *   - an absolute or cwd-relative path (matches Larastan's convention),
     *   - a glob pattern with `*` as the wildcard (e.g. `app/<wildcard>/config`).
     *
     * Resolution: literal is_dir() check first (so paths containing glob metacharacters
     * like `[` and `]` work), falling back to glob() expansion for actual patterns,
     * then realpath() and trailing separator. Psalm reports normalised absolute paths
     * for scanned files; realpath()-ing config dirs keeps both sides comparable across
     * symlinks.
     *
     * Individual entries that fail to resolve are dropped silently — common when a glob
     * matches nothing in a monorepo without packages yet. Passing an empty list
     * explicitly resets state (test convenience). Otherwise, when a non-empty input
     * resolves to no directories, a warning is emitted via $progress if provided —
     * that's the typo case where every env() call would otherwise be flagged.
     *
     * @param list<string> $directories
     */
    public static function init(array $directories, ?Progress $progress = null): void
    {
        $resolved = [];

        foreach ($directories as $directory) {
            // Try literal path first so paths with glob metacharacters (e.g. brackets in
            // `/home/user/dev[work]/config`) resolve correctly. Only fall back to glob()
            // when the literal path isn't a directory. GLOB_ONLYDIR drops non-directory
            // matches up-front; GLOB_NOSORT skips alphabetical sorting we don't need.
            if (\is_dir($directory)) {
                $matches = [$directory];
            } else {
                $globMatches = \glob($directory, \GLOB_ONLYDIR | \GLOB_NOSORT);
                $matches = $globMatches === false ? [] : $globMatches;
            }

            foreach ($matches as $match) {
                $real = \realpath($match);

                if ($real === false) {
                    continue;
                }

                $resolved[] = $real . \DIRECTORY_SEPARATOR;
            }
        }

        self::$configDirectories = \array_values(\array_unique($resolved));

        if ($directories !== [] && self::$configDirectories === [] && $progress instanceof \Psalm\Progress\Progress) {
            $progress->warning(
                'Laravel plugin: NoEnvOutsideConfig has no resolvable config directories — '
                    . "every env() call will be flagged. Inputs: '" . \implode("', '", $directories) . "'.",
            );
        }
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['env'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $filePath = $event->getStatementsSource()->getFilePath();

        // isTestFile is a single str_contains; isInsideConfigDirectory loops over every
        // configured directory. Cheaper check first to short-circuit test files.
        if (self::isTestFile($filePath) || self::isInsideConfigDirectory($filePath)) {
            return null;
        }

        IssueBuffer::accepts(
            new NoEnvOutsideConfig(
                'env() called outside config directory. '
                    . 'When config is cached, env() returns null. Use config() instead.',
                $event->getCodeLocation(),
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /** @psalm-external-mutation-free */
    private static function isInsideConfigDirectory(string $filePath): bool
    {
        foreach (self::$configDirectories as $directory) {
            if (\str_starts_with($filePath, $directory)) {
                return true;
            }
        }

        return false;
    }

    /** @psalm-pure */
    private static function isTestFile(string $filePath): bool
    {
        return \str_contains($filePath, \DIRECTORY_SEPARATOR . 'tests' . \DIRECTORY_SEPARATOR);
    }
}
