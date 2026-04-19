<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

/**
 * Reads the project's composer.json and vendor/ layout to answer two narrow
 * questions used by `init`:
 *
 *   - Is a given package declared as a dependency (require / require-dev)?
 *   - Is a given package physically installed under vendor/?
 *
 * Tolerant by design: a missing composer.json degrades to "no dependencies"
 * because users may legitimately run `init` in a fresh directory. But a
 * composer.json that *exists and is unreadable/malformed* is a user problem
 * worth surfacing — we record it in $parseWarning so the caller can display
 * it without blocking config generation.
 */
final class ComposerInspector
{
    private readonly string $cwd;

    /**
     * Declared dependency names read once at construction.
     *
     * `array<string, true>` is a set-of-strings: keys are package names
     * ("phpunit/phpunit") and values are an unused sentinel so callers can
     * query with a single `isset()`.
     *
     * @var array<string, true>
     */
    private readonly array $declaredDependencies;

    /**
     * Non-null when composer.json existed but could not be parsed (bad JSON,
     * wrong root type, read error). Null means either "absent" or "loaded OK".
     */
    public readonly ?string $parseWarning;

    public function __construct(string $cwd)
    {
        // Normalize so `cwd` ending in a separator doesn't produce `//vendor/…`.
        $this->cwd = \rtrim($cwd, \DIRECTORY_SEPARATOR);
        [$this->declaredDependencies, $this->parseWarning] = $this->loadDeclaredDependencies();
    }

    /**
     * True if the package is listed in require or require-dev.
     *
     * @psalm-mutation-free
     */
    public function hasDependency(string $package): bool
    {
        return isset($this->declaredDependencies[$package]);
    }

    /**
     * True if the package is installed under vendor/. Checks for the package's
     * own composer.json, which Composer always writes when it installs a
     * package. More reliable than checking for a directory alone (directories
     * can linger after incomplete removals).
     *
     * Reads the filesystem on every call by design: callers may invoke this
     * after running `composer require`, so the answer must reflect live state
     * rather than a snapshot from construction.
     */
    public function hasInstalledPackage(string $package): bool
    {
        $path = $this->cwd
            . \DIRECTORY_SEPARATOR . 'vendor'
            . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $package)
            . \DIRECTORY_SEPARATOR . 'composer.json';

        return \is_file($path);
    }

    /**
     * @return array{0: array<string, true>, 1: ?string}
     */
    private function loadDeclaredDependencies(): array
    {
        $path = $this->cwd . \DIRECTORY_SEPARATOR . 'composer.json';
        if (! \is_file($path)) {
            // No composer.json is a legitimate state for a fresh project.
            return [[], null];
        }

        $contents = @\file_get_contents($path);
        if (! \is_string($contents)) {
            return [[], \sprintf('Could not read %s.', $path)];
        }

        try {
            /** @var mixed $data */
            $data = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [[], \sprintf('%s is not valid JSON: %s', $path, $exception->getMessage())];
        }

        if (! \is_array($data)) {
            return [[], \sprintf('%s root must be a JSON object.', $path)];
        }

        $declared = [];
        foreach (['require', 'require-dev'] as $section) {
            $packages = $data[$section] ?? null;
            if (! \is_array($packages)) {
                continue;
            }
            foreach (\array_keys($packages) as $package) {
                if (\is_string($package)) {
                    $declared[$package] = true;
                }
            }
        }

        return [$declared, null];
    }
}
