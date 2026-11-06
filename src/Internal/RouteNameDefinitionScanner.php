<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * Collects the route names a project's own PHP sources spell out literally, as `->name('x')`,
 * `Route::name('x')`, `->name(name: 'x')` or `'as' => 'x'`.
 *
 * The booted app's router only holds the routes the boot actually registered. A route wrapped in
 * `if (config('feature.enabled'))`, or loaded by a provider that returned early, is absent from it
 * while still being defined in source. Callers use this set to tell "defined, but not registered by
 * this boot" from "defined nowhere".
 *
 * Nothing is evaluated or parsed into an AST: a file is lexed only when it contains a candidate
 * substring, which keeps a large app at a few hundred milliseconds. The lexer (not a regex) decides
 * what counts, so comments, strings and heredocs never define a name, and only one plain string
 * literal does: concatenation, interpolation, enums and variables do not. A name composed at runtime,
 * such as the leaf of `Route::name('barcode.')->group(...)`, is never spelled out and is not found. A
 * `->name('x')` on an unrelated class, or an `'as' => 'x'` pair outside a route action, counts as a
 * definition, which only hides a finding, never adds one.
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
     * @param (\Closure(string): bool)|null $isIgnored true for a file or directory (with a trailing
     *        separator) the analysis must not read, i.e. Psalm's `<ignoreFiles>`
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
                static function (string $path, mixed $_key, \RecursiveDirectoryIterator $inner) use ($vendorDir, $skipped, $isIgnored): bool {
                    if (!$inner->hasChildren()) {
                        return true;
                    }

                    // Case-insensitive: realpath() keeps the caller's casing on a case-insensitive filesystem,
                    // so a project root spelled differently from Composer's would walk vendor/.
                    return \strcasecmp($path, (string) $vendorDir) !== 0
                        && !\in_array(\basename($path), $skipped, true)
                        && (!$isIgnored instanceof \Closure || !$isIgnored($path . \DIRECTORY_SEPARATOR));
                },
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

            // Cheap literal pre-filter: almost no file passes it, so only a few are lexed.
            if ($code === false || (!\str_contains($code, 'name(') && !\str_contains($code, "'as'") && !\str_contains($code, '"as"'))) {
                continue;
            }

            foreach (self::definedNames($code) as $name) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function definedNames(string $code): array
    {
        $tokens = [];

        foreach (\PhpToken::tokenize($code) as $token) {
            // Whitespace, comments and the open tag.
            if (!$token->isIgnorable()) {
                $tokens[] = $token;
            }
        }

        $names = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $name = \in_array($tokens[$i]->id, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)
                ? self::nameCallArgument($tokens, $i + 1)
                : self::actionAsValue($tokens, $i);

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * `name('x')`, `name('x',)` or `name(name: 'x')`, starting at the method name after `->`, `?->` or `::`.
     *
     * @param list<\PhpToken> $tokens
     * @psalm-capabilities read-props
     */
    private static function nameCallArgument(array $tokens, int $at): ?string
    {
        if (!self::isIdentifier($tokens[$at] ?? null, 'name') || ($tokens[$at + 1]->text ?? '') !== '(') {
            return null;
        }

        $at += 2;

        // A named argument: `name: 'x'`.
        if (self::isIdentifier($tokens[$at] ?? null, 'name') && ($tokens[$at + 1]->text ?? '') === ':') {
            $at += 2;
        }

        $name = self::literal($tokens[$at] ?? null);
        $at++;

        if (($tokens[$at]->text ?? '') === ',') {
            $at++;
        }

        return $name !== null && ($tokens[$at]->text ?? '') === ')' ? $name : null;
    }

    /**
     * `'as' => 'x'` followed by the end of the array item, starting at the `'as'` key.
     *
     * @param list<\PhpToken> $tokens
     * @psalm-capabilities read-props
     */
    private static function actionAsValue(array $tokens, int $at): ?string
    {
        if (self::literal($tokens[$at]) !== 'as' || ($tokens[$at + 1]->id ?? null) !== \T_DOUBLE_ARROW) {
            return null;
        }

        // The literal must end the item: `'as' => 'a' . '.b'` names `a.b`, not `a`.
        return \in_array($tokens[$at + 3]->text ?? '', [',', ']', ')'], true) ? self::literal($tokens[$at + 2] ?? null) : null;
    }

    /** @psalm-capabilities read-props */
    private static function isIdentifier(?\PhpToken $token, string $name): bool
    {
        return $token instanceof \PhpToken && $token->id === \T_STRING && $token->text === $name;
    }

    /**
     * The value of a string token that is exactly one escape-free literal; double-quoted strings
     * with interpolation are not a single token, so they never reach here.
     *
     * @psalm-capabilities read-props
     */
    private static function literal(?\PhpToken $token): ?string
    {
        // A binary-prefixed literal (`b'x'`) is not worth modelling.
        if (!$token instanceof \PhpToken || $token->id !== \T_CONSTANT_ENCAPSED_STRING || \str_contains($token->text, '\\') || !\in_array($token->text[0], ["'", '"'], true)) {
            return null;
        }

        $value = \substr($token->text, 1, -1);

        return $value === '' ? null : $value;
    }
}
