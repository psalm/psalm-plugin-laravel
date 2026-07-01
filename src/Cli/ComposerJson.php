<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

/**
 * Read-only view over a project's composer.json.
 *
 * Shared by InitCommand and Diagnostics, which both parse the same file for
 * overlapping fields (vendor-dir, require.php, psr-4 autoload, package
 * presence) — extracted here after the two independently-normalised copies
 * had already drifted (#1195).
 *
 * Not @psalm-immutable: that annotation asserts every method (including the
 * static read() factory) is pure, but read() does real I/O (is_file,
 * file_get_contents) — PHP's native `readonly` already prevents mutation
 * after construction, which is the property that matters here.
 */
final readonly class ComposerJson
{
    /**
     * @param array{
     *     require?: array<string, string>,
     *     'require-dev'?: array<string, string>,
     *     autoload?: array{'psr-4'?: array<string, string|list<string>>},
     *     config?: array{'vendor-dir'?: string},
     * } $decoded
     * @psalm-mutation-free
     */
    private function __construct(private array $decoded) {}

    /**
     * Reads and decodes `<projectRoot>/composer.json`.
     *
     * Returns null only when the file is absent — callers can treat that as
     * "no composer.json, proceed with defaults". A file that exists but isn't
     * valid JSON (or doesn't decode to an object) throws instead: unlike
     * "absent", that state means something is actually broken, and a caller
     * that cares about the difference (e.g. `psalm-laravel diagnose`, which
     * exists specifically to surface broken installs) can catch it and say so
     * rather than silently falling back to defaults that look like ground truth.
     *
     * @throws \JsonException if composer.json exists but isn't valid JSON
     * @throws \RuntimeException if composer.json exists but can't be read, or doesn't decode to an object
     */
    public static function read(string $projectRoot): ?self
    {
        $path = $projectRoot . \DIRECTORY_SEPARATOR . 'composer.json';
        if (!\is_file($path)) {
            return null;
        }

        $contents = \file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(\sprintf('Could not read %s', $path));
        }

        // Validate the TOP-LEVEL type in object mode first: composer.json must
        // be a JSON object. In assoc mode both `{}` and `[]` decode to an empty
        // PHP array and are indistinguishable, so a bare JSON list (`[]`,
        // `[1,2]`) would sail through an `is_array()` check and be silently
        // treated as an empty-but-valid composer.json. `stdClass` is produced
        // only by a JSON object, so it cleanly accepts `{}`/`{...}` and rejects
        // any top-level array, string, number, bool, or null.
        /** @psalm-var mixed $probe */
        $probe = \json_decode($contents, false, flags: \JSON_THROW_ON_ERROR);
        if (!$probe instanceof \stdClass) {
            throw new \RuntimeException(\sprintf('%s did not decode to a JSON object', $path));
        }

        // Re-decode in assoc mode for the array-shaped reads below. This can't
        // throw or produce a non-array now that the object check above passed.
        /** @psalm-var array{require?: array<string, string>, 'require-dev'?: array<string, string>, autoload?: array{'psr-4'?: array<string, string|list<string>>}, config?: array{'vendor-dir'?: string}} $decoded */
        $decoded = (array) \json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        return new self($decoded);
    }

    /**
     * Raw `require.php` constraint string (e.g. `^8.2`), or null if unset. Not
     * resolved to a single version — the raw constraint is what's informative
     * to a human, and resolving it requires a per-release PHP version table.
     *
     * @psalm-mutation-free
     */
    public function requirePhp(): ?string
    {
        return $this->decoded['require']['php'] ?? null;
    }

    /**
     * True when $package is listed in `require` or `require-dev`. Version
     * constraints are ignored: presence is the only signal needed.
     *
     * @psalm-mutation-free
     */
    public function hasPackage(string $package): bool
    {
        return \array_key_exists($package, $this->decoded['require'] ?? [])
            || \array_key_exists($package, $this->decoded['require-dev'] ?? []);
    }

    /**
     * Composer's relocated vendor directory if configured, else 'vendor'.
     *
     * @psalm-mutation-free
     */
    public function vendorDir(): string
    {
        $configured = $this->decoded['config']['vendor-dir'] ?? null;
        if ($configured === null || $configured === '') {
            return 'vendor';
        }

        // Strip leading `./` and trailing slashes. Composer accepts both forms,
        // but psalm.xml paths are composer-root-relative without a prefix.
        $normalised = \rtrim(\preg_replace('#^\./#', '', $configured) ?? $configured, '/');

        return $normalised === '' ? 'vendor' : $normalised;
    }

    /**
     * `autoload.psr-4` directories. Order preserved, duplicates removed,
     * trailing slashes stripped.
     *
     * @return list<string>
     * @psalm-mutation-free
     */
    public function autoloadPsr4Dirs(): array
    {
        $psr4 = $this->decoded['autoload']['psr-4'] ?? [];

        $dirs = [];
        foreach ($psr4 as $paths) {
            $items = \is_string($paths) ? [$paths] : $paths;
            foreach ($items as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                $dir = \rtrim($candidate, '/');
                if ($dir === '' || \in_array($dir, $dirs, true)) {
                    continue;
                }

                $dirs[] = $dir;
            }
        }

        return $dirs;
    }
}
