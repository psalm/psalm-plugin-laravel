<?php

declare(strict_types=1);

/**
 * Compare every re-declared laravel/ai stub method's native parameter/return
 * types against the installed vendor package via reflection.
 *
 * Registered Psalm stubs (stubs/integrations/laravel-ai/) win over vendor
 * reflection during analysis (redeclaration is the declarative default for
 * these stubs), so Psalm itself never notices when a stub's native
 * signature stops matching a newer laravel/ai release. The PromptInjection
 * phpt suite and the fresh-app leg both type-check against the STUB, so a
 * drifted native signature (a param retyped, a return type changed) passes
 * every existing test silently. This script is the missing check: it reads
 * the stub source with php-parser (never loads it, since declaring the same
 * class the vendor autoloader already provides would fatal) and reflects the
 * real installed class, then diffs native types position-by-position.
 *
 * Only the pieces the type system can compare are checked: native parameter
 * and return types. Docblock-only precision (`@param non-empty-string`) is
 * unaffected and deliberately out of scope (see docs/contributing/README.md,
 * "Stub merging": Psalm-level narrowing beyond native types is expected and
 * is not drift).
 *
 * Usage: php bin/ci/check-laravel-ai-stub-parity.php [stubs-dir]
 * Exit codes: 0 = no drift found (beyond KNOWN_GAPS below), 1 = new drift
 * found or a stubbed class/method is missing from the installed vendor
 * package, 2 = laravel/ai not installed (soft skip, not a failure: the
 * calling CI leg already gates on this via SKIPIF/class-presence checks).
 */

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Pre-existing gaps found while building this checker, tracked separately
 * rather than fixed here: this script owns detection, not the stub files
 * themselves. Printed as findings either way, never silently hidden, they
 * just don't fail the job. An entry that stops reproducing (the stub was
 * fixed, or the finding no longer matches for any other reason) is reported
 * as stale rather than silently ignored or silently kept: see the
 * "Stale KNOWN_GAPS entries" check at the end of this script. Remove a
 * stale entry once you see that warning, so the checker starts enforcing
 * it like everything else.
 *
 * @var array<string, string>
 */
const KNOWN_GAPS = [
    'Laravel\Ai\Contracts\CanActAsTool::description' => 'stub return type is "string"; the installed package has widened it to "Stringable|string".',
];

if (!\class_exists(\Laravel\Ai\AnonymousAgent::class)) {
    echo "laravel/ai is not installed; nothing to compare.\n";
    exit(2);
}

$stubsDir = $argv[1] ?? dirname(__DIR__, 2) . '/stubs/integrations/laravel-ai';

/** @var list<string> $mismatches */
$mismatches = [];
/** @var list<string> $knownGaps */
$knownGaps = [];
/** @var array<string, true> $consumedGapKeys */
$consumedGapKeys = [];
$comparedMethods = 0;
$comparedClasses = 0;

$parser = (new ParserFactory())->createForNewestSupportedVersion();

foreach (findStubFiles($stubsDir) as $file) {
    $ast = $parser->parse(\file_get_contents($file) ?: '');
    if ($ast === null) {
        report($file, "{$file}: php-parser could not parse this stub", $mismatches, $knownGaps, $consumedGapKeys);
        continue;
    }

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver());
    $ast = $traverser->traverse($ast);

    foreach (findClassLikes($ast) as $classLike) {
        $fqcn = $classLike->namespacedName?->toString();
        if ($fqcn === null) {
            continue;
        }

        if (!\class_exists($fqcn) && !\interface_exists($fqcn) && !\trait_exists($fqcn)) {
            report($fqcn, "{$fqcn}: declared in {$file} but not found in the installed laravel/ai package (renamed or removed upstream?)", $mismatches, $knownGaps, $consumedGapKeys);
            continue;
        }

        $comparedClasses++;
        $reflectionClass = new \ReflectionClass($fqcn);

        foreach ($classLike->getMethods() as $method) {
            $methodName = $method->name->toString();
            $key = "{$fqcn}::{$methodName}";

            if (!$reflectionClass->hasMethod($methodName)) {
                report($key, "{$key}(): declared in the stub but not found on the installed class (renamed or removed upstream?)", $mismatches, $knownGaps, $consumedGapKeys);
                continue;
            }

            $comparedMethods++;
            diffSignature(
                $key,
                $method->params,
                $method->returnType,
                $reflectionClass->getMethod($methodName),
                $fqcn,
                $mismatches,
                $knownGaps,
                $consumedGapKeys,
            );
        }
    }

    foreach (findFunctions($ast) as $function) {
        $fqcn = $function->namespacedName?->toString();
        if ($fqcn === null || !\function_exists($fqcn)) {
            if ($fqcn !== null) {
                report($fqcn, "{$fqcn}(): declared in {$file} but not found in the installed laravel/ai package (renamed or removed upstream?)", $mismatches, $knownGaps, $consumedGapKeys);
            }
            continue;
        }

        $comparedMethods++;
        diffSignature($fqcn, $function->params, $function->returnType, new \ReflectionFunction($fqcn), null, $mismatches, $knownGaps, $consumedGapKeys);
    }
}

echo "Compared {$comparedMethods} method/function signatures across {$comparedClasses} classes against the installed laravel/ai package.\n";

if ($knownGaps !== []) {
    echo "\nKnown gaps (tracked separately, not new drift; see KNOWN_GAPS in this script):\n";
    foreach ($knownGaps as $knownGap) {
        echo " - {$knownGap}\n";
    }
}

// Stale KNOWN_GAPS entries: an entry that never matched anything this run
// means the mismatch it described no longer reproduces (its stub was fixed,
// the method was removed, or the entry was mistyped). That is good news,
// but a stale entry left in place would silently keep suppressing any FUTURE
// mismatch that happens to reuse the same key, which is exactly the kind of
// silent gap this checker exists to prevent. Loud and non-fatal: this is
// housekeeping, not drift.
$staleGapKeys = \array_diff(\array_keys(KNOWN_GAPS), \array_keys($consumedGapKeys));
if ($staleGapKeys !== []) {
    echo "\n";
    foreach ($staleGapKeys as $staleGapKey) {
        echo "::warning::Allowlisted gap for \"{$staleGapKey}\" in KNOWN_GAPS (bin/ci/check-laravel-ai-stub-parity.php) no longer reproduces. Remove this entry.\n";
    }
}

if ($mismatches !== []) {
    echo "\nSignature drift detected:\n";
    foreach ($mismatches as $mismatch) {
        echo " - {$mismatch}\n";
    }
    exit(1);
}

exit(0);

/**
 * @param list<string> $mismatches
 * @param list<string> $knownGaps
 * @param array<string, true> $consumedGapKeys
 */
function report(string $key, string $message, array &$mismatches, array &$knownGaps, array &$consumedGapKeys): void
{
    if (isset(KNOWN_GAPS[$key])) {
        $consumedGapKeys[$key] = true;
        $knownGaps[] = "{$message} ({$key}: " . KNOWN_GAPS[$key] . ')';

        return;
    }

    $mismatches[] = $message;
}

/** @return \Generator<string> */
function findStubFiles(string $dir): \Generator
{
    $iterator = new \RegexIterator(
        new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)),
        '/\.phpstub$/',
    );

    foreach ($iterator as $file) {
        yield $file->getPathname();
    }
}

/**
 * @param list<Node\Stmt> $nodes
 * @return list<Node\Stmt\ClassLike>
 */
function findClassLikes(array $nodes): array
{
    $found = [];
    foreach ($nodes as $node) {
        if ($node instanceof Node\Stmt\ClassLike) {
            $found[] = $node;
        }
        if (isset($node->stmts) && \is_array($node->stmts)) {
            /** @var list<Node\Stmt> $childStmts */
            $childStmts = $node->stmts;
            $found = [...$found, ...findClassLikes($childStmts)];
        }
    }

    return $found;
}

/**
 * @param list<Node\Stmt> $nodes
 * @return list<Node\Stmt\Function_>
 */
function findFunctions(array $nodes): array
{
    $found = [];
    foreach ($nodes as $node) {
        if ($node instanceof Node\Stmt\Function_) {
            $found[] = $node;
        }
        if (isset($node->stmts) && \is_array($node->stmts)) {
            /** @var list<Node\Stmt> $childStmts */
            $childStmts = $node->stmts;
            $found = [...$found, ...findFunctions($childStmts)];
        }
    }

    return $found;
}

/**
 * @param list<Node\Param> $stubParams
 * @param list<string> $mismatches
 * @param list<string> $knownGaps
 * @param array<string, true> $consumedGapKeys
 */
function diffSignature(
    string $label,
    array $stubParams,
    Node\Identifier|Node\Name|Node\ComplexType|null $stubReturnType,
    \ReflectionFunctionAbstract $reflected,
    ?string $enclosingFqcn,
    array &$mismatches,
    array &$knownGaps,
    array &$consumedGapKeys,
): void {
    // Plain functions have no `self`, and ReflectionFunction has no
    // getDeclaringClass(), so the resolution context is class-only.
    $declaringFqcn = $reflected instanceof \ReflectionMethod
        ? $reflected->getDeclaringClass()->getName()
        : null;

    $reflectedParams = $reflected->getParameters();

    foreach ($stubParams as $position => $stubParam) {
        $vendorParamType = isset($reflectedParams[$position]) ? $reflectedParams[$position]->getType() : null;

        // Skip when either side is untyped: nothing to compare, and a stub
        // legitimately adding a native type the vendor omits (or vice versa)
        // is a strictness choice, not drift.
        if ($stubParam->type === null || $vendorParamType === null || !isset($reflectedParams[$position])) {
            continue;
        }

        $stubType = stubTypeToString($stubParam->type, $stubParam->default, $enclosingFqcn);
        $vendorType = reflectionTypeToString($vendorParamType, $declaringFqcn);

        if ($stubType !== $vendorType) {
            $paramName = $stubParam->var instanceof Node\Expr\Variable && \is_string($stubParam->var->name)
                ? '$' . $stubParam->var->name
                : "position {$position}";
            report($label, "{$label}({$paramName}): stub says \"{$stubType}\", installed laravel/ai says \"{$vendorType}\"", $mismatches, $knownGaps, $consumedGapKeys);
        }
    }

    if ($stubReturnType !== null) {
        $vendorReturnType = $reflected->getReturnType();
        if ($vendorReturnType === null) {
            return;
        }

        $stubReturn = stubTypeToString($stubReturnType, null, $enclosingFqcn);
        $vendorReturn = reflectionTypeToString($vendorReturnType, $declaringFqcn);

        if ($stubReturn !== $vendorReturn) {
            report($label, "{$label}(): return type stub says \"{$stubReturn}\", installed laravel/ai says \"{$vendorReturn}\"", $mismatches, $knownGaps, $consumedGapKeys);
        }
    }
}

/**
 * Native-type-only normalization, comparable against reflectionTypeToString().
 * Docblock precision is intentionally invisible here; see the file docblock.
 *
 * `self`/`parent` are resolved to the enclosing class's FQCN because
 * ReflectionNamedType::getName() resolves them too (unlike `static`, which
 * both sides keep literal); otherwise every legitimately `self`-typed stub
 * method would misreport as drift.
 */
function stubTypeToString(Node\Identifier|Node\Name|Node\ComplexType $type, ?Node\Expr $default, ?string $enclosingFqcn): string
{
    if ($type instanceof Node\NullableType) {
        return '?' . stubTypeToString($type->type, null, $enclosingFqcn);
    }

    if ($type instanceof Node\UnionType) {
        $parts = \array_map(static fn(Node\Identifier|Node\Name|Node\IntersectionType $t): string => stubTypeToString($t, null, $enclosingFqcn), $type->types);
        \sort($parts);

        return \implode('|', $parts);
    }

    if ($type instanceof Node\IntersectionType) {
        $parts = \array_map(static fn(Node\Identifier|Node\Name $t): string => stubTypeToString($t, null, $enclosingFqcn), $type->types);
        \sort($parts);

        return \implode('&', $parts);
    }

    // Identifier | Name from here on: a plain scalar/class type.
    $name = $type->toString();

    if (\strtolower($name) === 'self' && $enclosingFqcn !== null) {
        $name = $enclosingFqcn;
    } elseif (\strtolower($name) === 'parent' && $enclosingFqcn !== null) {
        $parentClass = (new \ReflectionClass($enclosingFqcn))->getParentClass();
        $name = $parentClass !== false ? $parentClass->getName() : $name;
    }

    // PHP's deprecated implicit-nullable rule: a bare (non-"?", non-union)
    // type with an explicit `= null` default is nullable even without `?`,
    // and ReflectionNamedType::allowsNull() reports that. `mixed`/`null`
    // already cover null and cannot take a `?` prefix at all.
    $impliedNullable = $default instanceof Node\Expr\ConstFetch
        && \strtolower($default->name->toString()) === 'null'
        && !\in_array(\strtolower($name), ['mixed', 'null'], true);

    return ($impliedNullable ? '?' : '') . $name;
}

/**
 * Whether `getName()` resolves `self` to the declaring class is PHP-version
 * dependent, so both sides must be normalized explicitly. Resolving only the
 * stub side reported every fluent `self`-returning method as drift on the CI
 * runtime while staying clean locally.
 *
 * @param ?string $declaringFqcn declaring class, for `self`/`parent`
 */
function reflectionTypeToString(?\ReflectionType $type, ?string $declaringFqcn = null): string
{
    if ($type === null) {
        return 'NOTYPE';
    }

    if ($type instanceof \ReflectionUnionType) {
        $parts = \array_map(
            static fn(\ReflectionType $t): string => reflectionTypeToString($t, $declaringFqcn),
            $type->getTypes(),
        );
        \sort($parts);

        return \implode('|', $parts);
    }

    if ($type instanceof \ReflectionIntersectionType) {
        $parts = \array_map(
            static fn(\ReflectionType $t): string => reflectionTypeToString($t, $declaringFqcn),
            $type->getTypes(),
        );
        \sort($parts);

        return \implode('&', $parts);
    }

    // ReflectionNamedType from here on.
    $name = $type->getName();

    if ($declaringFqcn !== null) {
        if (\strtolower($name) === 'self') {
            $name = $declaringFqcn;
        } elseif (\strtolower($name) === 'parent') {
            $parentClass = (new \ReflectionClass($declaringFqcn))->getParentClass();
            $name = $parentClass !== false ? $parentClass->getName() : $name;
        }
    }

    $nullable = $type->allowsNull() && !\in_array(\strtolower($name), ['mixed', 'null'], true);

    return ($nullable ? '?' : '') . $name;
}
