<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * Collects the route names a project's own PHP sources spell out literally in a route definition:
 * `Route::get(...)->name('x')`, `Route::name('x')->get(...)` or `Route::get('/', ['as' => 'x'])`.
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
 * The match is a closed allowlist, so it cannot over-collect: anything the scanner does not fully
 * understand is declined, never treated as absent. A name is recorded only for a call chain rooted at
 * the `Route` facade, `$router` or `$this->router` that
 *  - registers exactly one route through `get/post/put/patch/delete/options/any/match/view/redirect/
 *    permanentRedirect/fallback`;
 *  - uses only methods verified in Laravel's `RouteRegistrar` / `Route` to leave the name alone
 *    (`middleware`, `where*`, `prefix`, `domain`, `can`, `scopeBindings`, ...; see NON_NAME_METHODS), so
 *    `attribute()`, `setAction()`, `uses()`, `when()` and macros decline the chain;
 *  - has a single name source: a literal `->name('x')`, or a literal `'as'` key in the route action
 *    (second argument; third for `match`; first for `fallback`);
 *  - has a readable action: a closure or arrow function, a string, `Controller::class`, a
 *    `[Controller::class, 'method']` callable array, or an array literal of literal keys without a spread.
 *    A variable, a call, a spread or a computed key could carry an `'as'` of its own.
 * `Schedule::command(...)->name('x')`, an unrelated `->name('x')`, a configuration array and the data
 * array of `Route::view()` never count. A route kept in a variable (`$route = Route::get(...);
 * $route->name('x')`) is not followed.
 *
 * Also declined, recording nothing: named arguments or a spread anywhere in the chain, resource-style
 * registrars (`resource`, `apiResource`, `singleton`, ...), and a chain with more than one name source,
 * because Laravel concatenates them. A name set before `group(...)` is a prefix whatever it looks like
 * (`'admin.'`, `'admin-'`, a variable), as is an `'as'` key of the group attributes; the routes inside
 * register under a composed name such as `admin.users`, never the bare `users`, so the whole group is
 * skipped. So is a group whose attributes are not a literal array of literal keys (an expression such as
 * `array_merge(...)`, a spread, a computed key). The composed name itself is never spelled out and is not
 * found. A group loaded from another file (`->group(base_path('routes/admin.php'))`) cannot be followed.
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
     * Route-registering methods, with the zero-based position of the route-action argument whose `'as'`
     * key names the route (null: no such argument, so only `->name()` can name the route). The data array
     * of `Route::view()` and anything else is never read for `'as'`.
     */
    private const ROUTE_VERBS = [
        'get' => 1, 'post' => 1, 'put' => 1, 'patch' => 1, 'delete' => 1, 'options' => 1, 'any' => 1, 'match' => 2, 'fallback' => 0,
        'view' => null, 'redirect' => null, 'permanentredirect' => null,
    ];

    /**
     * The only other methods allowed in a route chain: each was read in Laravel's `RouteRegistrar` /
     * `Route` and only sets a non-name attribute (middleware, constraints, URI prefix, domain, binding
     * and locking options, controller, namespace, metadata). Any method not listed here, a macro, `attribute()`,
     * `setAction()`, `uses()` or `when()` included, might change the name, so it declines the chain.
     */
    private const NON_NAME_METHODS = [
        'middleware', 'withoutmiddleware', 'can', 'where', 'wherealpha', 'wherealphanumeric', 'wherenumber', 'whereulid',
        'whereuuid', 'wherein', 'prefix', 'domain', 'controller', 'namespace', 'scopebindings', 'withoutscopedbindings',
        'withtrashed', 'missing', 'block', 'withoutblocking', 'metadata', 'defaults',
    ];

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
     * Walks `->method(...)` links from the first operator under a strict allowlist: a name is recorded only
     * when the chain registers exactly one route through a known verb, carries exactly one name source (a
     * literal `->name('x')`, or a literal `'as'` key in the verb's route-action array at its documented
     * position), and uses no named argument or spread, resource registrar or `group()`. Laravel
     * concatenates every name set in a chain, so any other shape is declined rather than guessed.
     *
     * A name set before `group(...)` is a prefix, whatever it looks like (`'admin.'`, `'admin-'`, a variable),
     * and so is an `'as'` key of its attributes: the group's routes register under a composed name, never
     * the bare `users`. Such a group, and a group whose attributes cannot be read (named arguments, spread,
     * a variable), is declined whole: the index of its closing parenthesis is returned to skip it.
     *
     * @param list<\PhpToken> $tokens
     * @return array{?string, int} the defined name, and the token index up to which routes are declined
     * @psalm-capabilities read-props
     */
    private static function analyseChain(array $tokens, int $at): array
    {
        $verbs = 0;
        $sources = 0;
        $literal = null;
        $groupEnd = 0;
        $unreadable = false;

        while (\in_array($tokens[$at]->id ?? null, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)
            // `as` and `match` are keyword tokens, not T_STRING, after `::`.
            && isset($tokens[$at + 1])
            && \preg_match('/^[a-z_]\w*$/i', $tokens[$at + 1]->text) === 1
            && ($tokens[$at + 2]->text ?? '') === '('
        ) {
            $open = $at + 2;
            $close = self::closingParenthesis($tokens, $open);

            if ($close === null) {
                break;
            }

            $method = \strtolower($tokens[$at + 1]->text);
            $arguments = self::arguments($tokens, $open, $close);

            foreach ($arguments as [$start]) {
                // A named argument (`name: 'x'`, `attributes: [...]`) or a spread.
                if ($tokens[$start]->id === \T_ELLIPSIS || ($tokens[$start + 1]->text ?? '') === ':') {
                    $unreadable = true;
                }
            }

            if ($method === 'name' || $method === 'as') {
                ++$sources;
                $literal = $method === 'name' && \count($arguments) === 1 ? self::literalArgument($tokens, $arguments[0]) : null;
            } elseif ($method === 'group') {
                $groupEnd = $close;

                // `Route::group($attributes, $routes)`: with one argument the receiver already carries them.
                if (\count($arguments) !== 1) {
                    $attributeNames = isset($arguments[0], $arguments[1]) ? self::keyedAsValues($tokens, $arguments[0]) : null;
                    $unreadable = $unreadable || $attributeNames === null;
                    $sources += \count($attributeNames ?? []);
                }
            } elseif (\array_key_exists($method, self::ROUTE_VERBS)) {
                ++$verbs;
                $slot = self::ROUTE_VERBS[$method];

                if ($slot !== null && isset($arguments[$slot])) {
                    $asValues = self::actionAsValues($tokens, $arguments[$slot]);
                    $unreadable = $unreadable || $asValues === null;
                    $sources += \count($asValues ?? []);
                    $literal = $asValues[0] ?? $literal;
                }
            } elseif (!\in_array($method, self::NON_NAME_METHODS, true)) {
                // A resource registrar or a method that is not known to leave the name alone.
                $unreadable = true;
            }

            $at = $close + 1;
        }

        if ($groupEnd !== 0) {
            return [null, $sources > 0 || $unreadable ? $groupEnd : 0];
        }

        // A trailing dot is a prefix (`Route::name('admin.')->get(...)`), never a complete name.
        $defined = !$unreadable && $verbs === 1 && $sources === 1 && $literal !== null && !\str_ends_with($literal, '.');

        return [$defined ? $literal : null, 0];
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
     * The top-level arguments of the call between $open and $close, as `[first token, end token)` ranges.
     *
     * @param list<\PhpToken> $tokens
     * @return list<array{int, int}>
     * @psalm-capabilities read-props
     */
    private static function arguments(array $tokens, int $open, int $close): array
    {
        $ranges = [];
        $depth = 0;
        $start = $open + 1;

        for ($i = $start; $i < $close; $i++) {
            $text = $tokens[$i]->text;

            if (in_array($text, ['(', '[', '{', '${'], true)) {
                ++$depth;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                --$depth;
            } elseif ($text === ',' && $depth === 0) {
                $ranges[] = [$start, $i];
                $start = $i + 1;
            }
        }

        // A trailing comma leaves no final argument.
        if ($start < $close) {
            $ranges[] = [$start, $close];
        }

        return $ranges;
    }

    /**
     * The literal when the argument is exactly one plain string literal.
     *
     * @param list<\PhpToken> $tokens
     * @param array{int, int} $argument
     * @psalm-capabilities read-props
     */
    private static function literalArgument(array $tokens, array $argument): ?string
    {
        return $argument[1] - $argument[0] === 1 ? self::literal($tokens[$argument[0]]) : null;
    }

    /**
     * The items of the argument when it is exactly one array literal, as `[first token, end token)` ranges.
     *
     * @param list<\PhpToken> $tokens
     * @param array{int, int} $argument
     * @return list<array{int, int}>|null
     * @psalm-capabilities read-props
     */
    private static function arrayItems(array $tokens, array $argument): ?array
    {
        [$start, $end] = $argument;

        if ($tokens[$start]->text === '[') {
            $inner = $start + 1;
        } elseif ($tokens[$start]->id === \T_ARRAY && ($tokens[$start + 1]->text ?? '') === '(') {
            $inner = $start + 2;
        } else {
            return null;
        }

        $items = [];
        $depth = 0;
        $itemStart = $inner;

        // The last token of the argument must be the array's own closing bracket: `[...] + $other` is no literal.
        for ($i = $inner; $i < $end; $i++) {
            $text = $tokens[$i]->text;

            if (in_array($text, ['(', '[', '{', '${'], true)) {
                ++$depth;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if (--$depth < 0) {
                    if ($i !== $end - 1) {
                        return null;
                    }

                    // A trailing comma leaves no final item.
                    if ($i > $itemStart) {
                        $items[] = [$itemStart, $i];
                    }

                    return $items;
                }
            } elseif ($text === ',' && $depth === 0) {
                $items[] = [$itemStart, $i];
                $itemStart = $i + 1;
            }
        }

        return null;
    }

    /**
     * The values of every `'as'` key of an array literal whose items are all `'literal key' => value`
     * pairs; null (unknown) for anything else: not an array literal, a spread, a computed or escaped key,
     * a keyless item. A value that is not one plain literal is null in the list.
     *
     * @param list<\PhpToken> $tokens
     * @param array{int, int} $argument
     * @return list<?string>|null
     * @psalm-capabilities read-props
     */
    private static function keyedAsValues(array $tokens, array $argument): ?array
    {
        $items = self::arrayItems($tokens, $argument);

        if ($items === null) {
            return null;
        }

        $values = [];

        foreach ($items as [$start, $end]) {
            $key = self::literal($tokens[$start]);

            if ($key === null || $end - $start < 3 || $tokens[$start + 1]->id !== \T_DOUBLE_ARROW) {
                return null;
            }

            if ($key === 'as') {
                $values[] = $end - $start === 3 ? self::literal($tokens[$start + 2]) : null;
            }
        }

        return $values;
    }

    /**
     * The `'as'` values of a route action, or null when the action cannot be read. Readable: a closure or
     * arrow function, a string, `Controller::class`, a `[Controller::class, 'method']` callable array, or
     * an array literal of literal keys. A variable, a call, a spread or a computed key could carry an
     * `'as'` of its own, so those are unknown.
     *
     * @param list<\PhpToken> $tokens
     * @param array{int, int} $argument
     * @return list<?string>|null
     * @psalm-capabilities read-props
     */
    private static function actionAsValues(array $tokens, array $argument): ?array
    {
        [$start, $end] = $argument;
        $first = $tokens[$start];

        if ($first->id === \T_STATIC && isset($tokens[$start + 1])) {
            $first = $tokens[$start + 1];
        }

        if ($first->id === \T_FUNCTION || $first->id === \T_FN) {
            return [];
        }

        if ($end - $start === 1 && $first->id === \T_CONSTANT_ENCAPSED_STRING) {
            return [];
        }

        if (self::isClassConstant($tokens, $start, $end)) {
            return [];
        }

        $items = self::arrayItems($tokens, $argument);

        if ($items !== null && \count($items) === 2 && (self::isClassConstant($tokens, ...$items[0]) || self::literalArgument($tokens, $items[0]) !== null) && self::literalArgument($tokens, $items[1]) !== null) {
            return [];
        }

        return self::keyedAsValues($tokens, $argument);
    }

    /**
     * Whether the range is exactly `Name::class`.
     *
     * @param list<\PhpToken> $tokens
     * @psalm-capabilities read-props
     */
    private static function isClassConstant(array $tokens, int $start, int $end): bool
    {
        return $end - $start === 3
            && \in_array($tokens[$start]->id, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_STATIC], true)
            && $tokens[$start + 1]->id === \T_DOUBLE_COLON
            && \strtolower($tokens[$start + 2]->text) === 'class';
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
