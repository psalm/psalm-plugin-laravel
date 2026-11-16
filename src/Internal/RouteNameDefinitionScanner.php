<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * Collects the route names a project's own PHP sources spell out literally in a route definition:
 * `Route::get(...)->name('x')`, `Route::name('x')->get(...)`, `->name(name: 'x')` or `Route::get('/', ['as' => 'x'])`.
 *
 * The booted app's router only holds the routes the boot actually registered. A route wrapped in
 * `if (config('feature.enabled'))`, or loaded by a provider that returned early, is absent from it
 * while still being defined in source. Callers use this set to tell "defined, but not registered by
 * this boot" from "defined nowhere".
 *
 * Nothing is evaluated or parsed into an AST: a file is lexed only when it contains a candidate
 * substring, which keeps a large app at a few hundred milliseconds. The lexer (not a regex) decides
 * what counts, so comments, strings and heredocs never define a name, and only one plain string
 * literal does: concatenation, interpolation, enums and variables do not.
 *
 * Provenance: only a call chain rooted at the `Route` facade, `$router` or `$this->router` counts. A
 * `Schedule::command(...)->name('x')`, an unrelated `->name('x')` or a configuration array with an
 * `'as'` key is not a route definition. An `'as'` key counts only in an array passed directly to a
 * call of such a chain. A route registered through a stored registrar or a variable
 * (`$route = Route::get(...); $route->name('x')`) is not followed.
 *
 * Prefixes: Laravel concatenates every name set in a chain, so a literal counts only when it is the
 * chain's sole name. A name set before `group(...)` (or an `'as'` key of its attributes) is a prefix
 * whatever it looks like (`'admin.'`, `'admin-'`, a variable); the routes inside register under a
 * composed name, such as `admin.users`, never the bare `users`, so none of them is recorded. The
 * composed name itself is never spelled out and is not found. A group loaded from another file
 * (`->group(base_path('routes/admin.php'))`) cannot be followed.
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
     * Route methods whose array argument is a route action or group attributes, where an `'as'` key names
     * the route. `middleware(['as' => ...])` and the like take no such array.
     */
    private const ACTION_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'group', 'view', 'redirect', 'permanentredirect', 'fallback'];

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
            if ($code === false || (!\str_contains($code, 'name(') && !\str_contains($code, 'as(') && !\str_contains($code, "'as'") && !\str_contains($code, '"as"'))) {
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
        // Routes inside a name-prefixed `group(...)` are registered under a composed name, so none of them
        // is a definition of its own literal.
        $declinedUntil = 0;

        for ($i = 0; $i < $count; $i++) {
            $chainStart = $i < $declinedUntil ? null : self::chainStart($tokens, $i);

            if ($chainStart === null) {
                continue;
            }

            [$name, $groupEnd] = self::analyseChain($tokens, $chainStart);

            if ($name !== null) {
                $names[] = $name;
            }

            $declinedUntil = \max($declinedUntil, $groupEnd);
        }

        return $names;
    }

    /**
     * The index of the first `::` or `->` after a route-definition root: the `Route` facade, `$router`
     * or `$this->router`. Only a chain that starts there is proven to register a route; `Schedule::command()->name()`
     * or an unrelated `$builder->name()` is not.
     *
     * @param list<\PhpToken> $tokens
     * @psalm-capabilities read-props
     */
    private static function chainStart(array $tokens, int $at): ?int
    {
        $token = $tokens[$at];
        $previous = $tokens[$at - 1] ?? null;

        if ($previous instanceof \PhpToken && \in_array($previous->id, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)) {
            // `$this->router` is the one member access that roots a chain, handled from `$this` below.
            return null;
        }

        if (\in_array($token->id, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
            && ($token->text === 'Route' || \str_ends_with($token->text, '\\Route'))
        ) {
            return ($tokens[$at + 1]->id ?? null) === \T_DOUBLE_COLON ? $at + 1 : null;
        }

        if ($token->id !== \T_VARIABLE) {
            return null;
        }

        if ($token->text === '$router') {
            return ($tokens[$at + 1]->id ?? null) === \T_OBJECT_OPERATOR ? $at + 1 : null;
        }

        if ($token->text === '$this'
            && ($tokens[$at + 1]->id ?? null) === \T_OBJECT_OPERATOR
            && self::isIdentifier($tokens[$at + 2] ?? null, 'router')
            && ($tokens[$at + 3]->id ?? null) === \T_OBJECT_OPERATOR
        ) {
            return $at + 3;
        }

        return null;
    }

    /**
     * Walks `->method(...)` links from the first operator. A link is `name(...)`, `as(...)` or an `'as'` key
     * of an array argument; each contributes to the route's name, and Laravel concatenates them, so the
     * literal counts only when it is the single source.
     *
     * A name set before `group(...)` is a prefix, whatever its text (`'admin.'`, `'admin-'`, a variable): the
     * group's routes get a composed name, so the group is declined, and the index of its closing parenthesis
     * is returned to skip it.
     *
     * @param list<\PhpToken> $tokens
     * @return array{?string, int} the defined name, and the token index up to which routes are declined
     * @psalm-capabilities read-props
     */
    private static function analyseChain(array $tokens, int $at): array
    {
        $sources = 0;
        $literal = null;
        $groupEnd = 0;

        while (\in_array($tokens[$at]->id ?? null, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)
            // `as` is a keyword token, not a T_STRING, after `::`.
            && \in_array($tokens[$at + 1]->id ?? null, [\T_STRING, \T_AS], true)
            && ($tokens[$at + 2]->text ?? '') === '('
        ) {
            $open = $at + 2;
            $close = self::closingParenthesis($tokens, $open);

            if ($close === null) {
                break;
            }

            $method = \strtolower($tokens[$at + 1]->text);
            $asValues = \in_array($method, self::ACTION_METHODS, true) ? self::asKeyValues($tokens, $open, $close) : [];

            if ($method === 'name' || $method === 'as') {
                ++$sources;
                $literal = self::singleLiteralArgument($tokens, $open, $close);
            } else {
                $sources += \count($asValues);
                $literal = $asValues[0] ?? $literal;
            }

            if ($method === 'group') {
                $groupEnd = $close;
            }

            $at = $close + 1;
        }

        if ($groupEnd !== 0) {
            return [null, $sources > 0 ? $groupEnd : 0];
        }

        // A trailing dot is a prefix (`Route::name('admin.')->get(...)`), never a complete name.
        return [$sources === 1 && $literal !== null && !\str_ends_with($literal, '.') ? $literal : null, 0];
    }

    /**
     * The index of the `)` that closes the `(` at $open.
     *
     * @param list<\PhpToken> $tokens
     * @psalm-capabilities read-props
     */
    private static function closingParenthesis(array $tokens, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $count = \count($tokens); $i < $count; $i++) {
            if ($tokens[$i]->text === '(') {
                ++$depth;
            } elseif ($tokens[$i]->text === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * `name('x')`, `name('x',)` or `name(name: 'x')`: the literal, or null when the argument is anything else.
     *
     * @param list<\PhpToken> $tokens
     * @psalm-capabilities read-props
     */
    private static function singleLiteralArgument(array $tokens, int $open, int $close): ?string
    {
        $at = $open + 1;

        // A named argument: `name: 'x'`.
        if (($tokens[$at]->id ?? null) === \T_STRING && ($tokens[$at + 1]->text ?? '') === ':') {
            $at += 2;
        }

        $name = self::literal($tokens[$at] ?? null);
        ++$at;

        if (($tokens[$at]->text ?? '') === ',') {
            ++$at;
        }

        return $name !== null && $at === $close ? $name : null;
    }

    /**
     * The value of every `'as' => ...` pair of an array that is itself an argument of the call at $open,
     * null for a value that is not one plain literal. An `'as'` key nested deeper, or in a closure body, is
     * not a route action.
     *
     * @param list<\PhpToken> $tokens
     * @return list<?string>
     * @psalm-capabilities read-props
     */
    private static function asKeyValues(array $tokens, int $open, int $close): array
    {
        $values = [];
        // One entry per open bracket: whether it is an array literal passed directly as an argument.
        $stack = [];

        for ($i = $open + 1; $i < $close; $i++) {
            $text = $tokens[$i]->text;

            if (in_array($text, ['[', '(', '{', '${'], true)) {
                $isArray = $text === '[' || ($text === '(' && $tokens[$i - 1]->id === \T_ARRAY);
                $start = $text === '[' ? $i : $i - 1;
                $stack[] = $stack === [] && $isArray && \in_array($tokens[$start - 1]->text, ['(', ','], true);

                continue;
            }

            if (in_array($text, [']', ')', '}'], true)) {
                \array_pop($stack);

                continue;
            }

            if ($stack === [true]
                && self::literal($tokens[$i]) === 'as'
                && ($tokens[$i + 1]->id ?? null) === \T_DOUBLE_ARROW
                && \in_array($tokens[$i - 1]->text, ['[', '(', ','], true)
            ) {
                // The literal must end the item: `'as' => 'a' . '.b'` names `a.b`, not `a`.
                $values[] = \in_array($tokens[$i + 3]->text ?? '', [',', ']', ')'], true) ? self::literal($tokens[$i + 2] ?? null) : null;
            }
        }

        return $values;
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
