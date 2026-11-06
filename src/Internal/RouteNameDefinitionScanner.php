<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * Collects the route names a project's own PHP sources spell out literally, as `->name('x')` or
 * `'as' => 'x'`.
 *
 * The booted app's router only holds the routes the boot actually registered. A route wrapped in
 * `if (config('feature.enabled'))`, or loaded by a provider that returned early, is absent from it
 * while still being defined in source. Callers use this set to tell "defined, but not registered by
 * this boot" from "defined nowhere".
 *
 * This is a text scan, deliberately: it never evaluates anything and costs a few hundred
 * milliseconds on a large app (a file is parsed only when it contains a candidate substring). A
 * name composed at runtime, such as the leaf of `Route::name('barcode.')->group(...)`, is
 * never spelled out and is not found. A commented-out definition counts as one, and so does a
 * `->name('x')` on an unrelated class: both only hide a finding, never add one.
 *
 * Scans every `.php` file (Blade templates excluded) under the project root, not only Psalm's
 * `<projectFiles>`: route files commonly sit next to `src/` rather than inside it
 * (`packages/<name>/routes/web.php`).
 *
 * @internal
 */
final class RouteNameDefinitionScanner
{
    /**
     * Directory basenames never worth entering: dependencies, caches and VCS data hold no project
     * routes, and `tests` fixtures register routes the application never serves.
     */
    private const SKIPPED_DIRECTORIES = ['node_modules', '.git', 'storage', 'public', 'tests'];

    /**
     * A single quoted string without escapes, or a double quoted one without escapes or
     * interpolation, so every captured name is exactly the runtime name. The literal must also end
     * its expression (`)` after `->name(`, `,` or `]` after `'as' =>`): `'as' => 'a' . '.b'` names
     * `a.b`, not `a`.
     */
    private const DEFINITION_PATTERN = <<<'REGEX'
        /->name\(\s*(?:'(?<single>[^'\\]*)'|"(?<double>[^"\\$]*)")\s*,?\s*\)|['"]as['"]\s*=>\s*(?:'(?<asSingle>[^'\\]*)'|"(?<asDouble>[^"\\$]*)")(?=\s*[,\])])/
        REGEX;

    /** @var list<string> */
    private const NAME_GROUPS = ['single', 'double', 'asSingle', 'asDouble'];

    /**
     * @param (\Closure(string): bool)|null $isIgnored true for a file the analysis must not read (Psalm's `<ignoreFiles>`)
     * @return array<string, true> route names keyed by name
     */
    public static function scan(string $projectRoot, ?\Closure $isIgnored = null): array
    {
        $root = \realpath($projectRoot);

        if ($root === false) {
            return [];
        }

        // VendorDirectory::path() is null only when Composer's runtime API is unavailable. Scanning
        // vendor/ would then cost seconds, so fall back to the conventional directory name.
        $vendorPath = VendorDirectory::path();
        $vendorDir = $vendorPath === null ? null : \realpath($vendorPath);
        $skipped = $vendorPath === null ? [...self::SKIPPED_DIRECTORIES, 'vendor'] : self::SKIPPED_DIRECTORIES;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                static fn(string $path, mixed $_key, \RecursiveDirectoryIterator $inner): bool => !$inner->hasChildren()
                    || ($path !== $vendorDir && !\in_array(\basename($path), $skipped, true)),
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            // An unreadable subdirectory must not abort the scan.
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        $names = [];

        /** @var string $path */
        foreach ($iterator as $path) {
            if (!\str_ends_with($path, '.php') || \str_ends_with($path, '.blade.php')) {
                continue;
            }

            if ($isIgnored instanceof \Closure && $isIgnored($path)) {
                continue;
            }

            $code = @\file_get_contents($path);

            // Cheap literal pre-filter: almost no file passes it, so the regex runs on a few.
            if ($code === false || (!\str_contains($code, '->name(') && !\str_contains($code, "'as'") && !\str_contains($code, '"as"'))) {
                continue;
            }

            if (\preg_match_all(self::DEFINITION_PATTERN, $code, $matches, \PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                foreach (self::NAME_GROUPS as $group) {
                    $name = $match[$group] ?? '';

                    if ($name !== '') {
                        $names[$name] = true;
                    }
                }
            }
        }

        return $names;
    }
}
