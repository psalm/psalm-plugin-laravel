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
 * Signature metadata is compared beyond types: parameter count, parameter
 * names position-by-position, optionality/default expressions, by-reference
 * and variadic flags, and the native return type/by-reference flag. Count and
 * names are not cosmetic. A stub missing a trailing parameter makes Psalm
 * reject a call that is valid at runtime, and a parameter renamed upstream
 * silently disarms every `@psalm-taint-sink <kind> $name` hung off the old name
 * while every existing test stays green.
 *
 * Public/protected vendor methods and properties are also checked against
 * the stub. Psalm actually MERGES an omitted one in from the real class
 * rather than erasing it (verified against Psalm 6.16.1 and 7.0.0-beta19),
 * so this isn't a correctness check — it's a taint-review tripwire: a
 * merged-in method has whatever taint annotations the real class has, i.e.
 * none. `Tools\Request::validate()` shipping without `@psalm-taint-source
 * input` is exactly this failure mode.
 *
 * The interface list IS genuinely wiped on redeclaration, unlike
 * methods/properties, so a stub `implements`/`extends` clause missing one
 * really does break `instanceof`/type-hint compatibility. Checked separately
 * below, both directions (missing and stale), against each declared name's
 * own interface-extends-interface closure (Reflection can't distinguish an
 * explicit name from one only reachable through it) and exempting
 * `Stringable`, which PHP grants implicitly to any `__toString()` class.
 *
 * Docblock-only precision (`@param non-empty-string`) is unaffected and
 * deliberately out of scope (see docs/contributing/README.md, "Stub merging":
 * Psalm-level narrowing beyond native types is expected and is not drift).
 * That exemption has a cost worth knowing: a docblock that NARROWS a native
 * `array` to `string[]` when upstream documents a union is real drift this
 * script is blind to, because both sides are natively `array`. Fixtures cover
 * those, e.g. tests/Type/tests/PromptInjection/EmbeddingsAcceptsFileInputs.phpt.
 *
 * A stub method tagged `@since X.Y.Z` is exempt from the "declared in the
 * stub but not found on the installed class" finding while the installed
 * laravel/ai is older than X.Y.Z: the method genuinely doesn't exist yet on
 * that floor, so it isn't drift. The gate only reads dotted-numeric versions
 * on both sides (an unpinned `dev-master`/`x-dev` install falls through to
 * the normal check instead of being silently exempted), and once the
 * installed version reaches X.Y.Z the tag stops helping, so a real rename or
 * removal upstream is still caught.
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
use PhpParser\PrettyPrinter\Standard;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Escape hatch for a known mismatch that is tracked separately rather than
 * fixed here: this script owns detection, not the stub files themselves. An
 * entry is keyed by the reported label (`Fqcn::method`, or the function FQN)
 * and is printed as a finding either way, never silently hidden, it just does
 * not fail the job. An entry that stops reproducing is reported as stale
 * rather than silently kept: see the "Stale KNOWN_GAPS entries" check at the
 * end of this script. Remove a stale entry once you see that warning, so the
 * checker starts enforcing it like everything else.
 *
 * Empty is the desired steady state. It got there the intended way: the one
 * original entry (`Contracts\CanActAsTool::description`) was fixed in #1330 and
 * the stale warning named it on the next run.
 *
 * @var array<string, string>
 */
const KNOWN_GAPS = [];

/**
 * Public/protected vendor members intentionally omitted from a redeclaration.
 * Keep this narrow and temporary: every entry is reported and consumed, and
 * stale entries warn so an omission cannot become a permanent blind spot.
 *
 * @var array<string, string>
 */
const INTENTIONAL_OMISSIONS = [];

if (!\class_exists(\Laravel\Ai\AnonymousAgent::class)) {
    echo "laravel/ai is not installed; nothing to compare.\n";
    exit(2);
}

$stubsDir = $argv[1] ?? dirname(__DIR__, 2) . '/stubs/integrations/laravel-ai';
$installedVersion = installedLaravelAiVersion();

/** @var list<string> $mismatches */
$mismatches = [];
/** @var list<string> $knownGaps */
$knownGaps = [];
/** @var list<string> $versionGated */
$versionGated = [];
/** @var array<string, true> $consumedGapKeys */
$consumedGapKeys = [];
$consumedOmissionKeys = [];
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
        $declaredMethodNames = [];
        $reflectionClass = new \ReflectionClass($fqcn);

        foreach ($classLike->getMethods() as $method) {
            $methodName = $method->name->toString();
            $declaredMethodNames[$methodName] = true;
            $key = "{$fqcn}::{$methodName}";

            if (!$reflectionClass->hasMethod($methodName)) {
                if (versionGateApplies($method->getDocComment(), $installedVersion, $key, $versionGated)) {
                    continue;
                }

                report($key, "{$key}(): declared in the stub but not found on the installed class (renamed or removed upstream?)", $mismatches, $knownGaps, $consumedGapKeys);
                continue;
            }

            $comparedMethods++;
            diffSignature(
                $key,
                $method->params,
                $method->returnType,
                $method->byRef,
                $reflectionClass->getMethod($methodName),
                $fqcn,
                $mismatches,
                $knownGaps,
                $consumedGapKeys,
            );
        }

        // Taint-review tripwire, not a correctness check (see file docblock).
        // A trait counts as providing a method only when concretely
        // implemented, not just required abstractly. Only laravel/ai's own
        // implementation counts; framework trait helpers (e.g.
        // SerializesModels) aren't this integration's API contract.
        foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->getDeclaringClass()->getName() !== $fqcn || !isLaravelAiSource($method->getFileName())) {
                continue;
            }

            if (isset($declaredMethodNames[$method->getName()]) || traitProvidesConcreteMethod($classLike, $method->getName())) {
                continue;
            }

            $visibility = $method->isProtected() ? 'protected' : 'public';
            reportOmission(
                "{$fqcn}::{$method->getName()}",
                "{$fqcn}::{$method->getName()}(): {$visibility} method exists in installed laravel/ai but is missing from the stub",
                $mismatches,
                $knownGaps,
                $consumedGapKeys,
                $consumedOmissionKeys,
            );
        }

        $declaredPropertyNames = declaredPropertyNames($classLike);
        foreach ($reflectionClass->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $fqcn
                || $property->isPrivate()
                || !isLaravelAiSource($property->getDeclaringClass()->getFileName())
                || isset($declaredPropertyNames[$property->getName()])
                || traitProvidesProperty($classLike, $property->getName())) {
                continue;
            }

            reportOmission(
                "{$fqcn}::\${$property->getName()}",
                "{$fqcn}::\${$property->getName()}: public/protected property exists in installed laravel/ai but is missing from the stub",
                $mismatches,
                $knownGaps,
                $consumedGapKeys,
                $consumedOmissionKeys,
            );
        }

        // Unlike methods/properties, this one is real erasure (file docblock).
        // `parent_classes` isn't wiped, so Psalm still derives interfaces
        // inherited from the real parent without the stub repeating them.
        // Each literal name in the stub's clause is expanded to its own
        // interface-extends-interface closure (e.g. `IteratorAggregate`
        // implies `Traversable`) before comparing, matching what a real
        // `implements IteratorAggregate` in the stub would also grant.
        // `Stringable` is PHP-implicit on any class with `__toString()`, on
        // both the real class and the stub, so it's exempt rather than
        // needing to be spelled out.
        $clauseWord = $classLike instanceof Node\Stmt\Interface_ ? 'extends' : 'implements';
        $declaredInterfaceClosure = declaredInterfaceClosure($classLike);
        if (isset($declaredMethodNames['__toString'])) {
            $declaredInterfaceClosure['Stringable'] = true;
        }

        $parentClass = $reflectionClass->getParentClass();
        $inheritedInterfaceNames = $parentClass !== false ? \array_flip($parentClass->getInterfaceNames()) : [];

        foreach ($reflectionClass->getInterfaceNames() as $interfaceName) {
            if (isset($declaredInterfaceClosure[$interfaceName]) || isset($inheritedInterfaceNames[$interfaceName])) {
                continue;
            }

            reportOmission(
                "{$fqcn} implements {$interfaceName}",
                "{$fqcn}: implements {$interfaceName} in the installed laravel/ai, but the stub's `{$clauseWord}` clause omits it (Psalm wipes the interface list on redeclaration)",
                $mismatches,
                $knownGaps,
                $consumedGapKeys,
                $consumedOmissionKeys,
            );
        }

        // The reverse direction: a stale interface the stub still claims but
        // the installed class no longer implements (removed/renamed
        // upstream). This is a hard mismatch, not an omission — Psalm would
        // let a project treat the class as that type when it no longer is.
        $realInterfaceNames = \array_flip($reflectionClass->getInterfaceNames());
        foreach (declaredInterfaceNames($classLike) as $interfaceName => $_) {
            if (isset($realInterfaceNames[$interfaceName])) {
                continue;
            }

            report(
                "{$fqcn} implements {$interfaceName} (stale)",
                "{$fqcn}: stub's `{$clauseWord}` clause declares {$interfaceName}, but the installed class doesn't implement it (renamed or removed upstream?)",
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
        diffSignature($fqcn, $function->params, $function->returnType, $function->byRef, new \ReflectionFunction($fqcn), null, $mismatches, $knownGaps, $consumedGapKeys);
    }
}

echo "Compared {$comparedMethods} method/function signatures across {$comparedClasses} classes against the installed laravel/ai package.\n";

if ($knownGaps !== []) {
    echo "\nKnown gaps (tracked separately, not new drift; see KNOWN_GAPS in this script):\n";
    foreach ($knownGaps as $knownGap) {
        echo " - {$knownGap}\n";
    }
}

if ($versionGated !== []) {
    echo "\nVersion-gated (installed laravel/ai {$installedVersion} predates the stub method's @since tag):\n";
    foreach ($versionGated as $gated) {
        echo " - {$gated}\n";
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

$staleOmissionKeys = \array_diff(\array_keys(INTENTIONAL_OMISSIONS), \array_keys($consumedOmissionKeys));
if ($staleOmissionKeys !== []) {
    echo "\n";
    foreach ($staleOmissionKeys as $staleOmissionKey) {
        echo "::warning::Intentional omission for \"{$staleOmissionKey}\" in INTENTIONAL_OMISSIONS (bin/ci/check-laravel-ai-stub-parity.php) no longer reproduces. Remove this entry.\n";
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

/**
 * Report an intentionally omitted member without making it disappear from
 * the output. KNOWN_GAPS is for mismatched declarations; this list is for
 * deliberate, documented omissions where a full redeclaration is out of
 * scope.
 *
 * @param list<string> $mismatches
 * @param list<string> $knownGaps
 * @param array<string, true> $consumedGapKeys
 * @param array<string, true> $consumedOmissionKeys
 */
function reportOmission(string $key, string $message, array &$mismatches, array &$knownGaps, array &$consumedGapKeys, array &$consumedOmissionKeys): void
{
    if (isset(INTENTIONAL_OMISSIONS[$key])) {
        $consumedOmissionKeys[$key] = true;
        $knownGaps[] = "{$message} ({$key}: " . INTENTIONAL_OMISSIONS[$key] . ')';

        return;
    }

    report($key, $message, $mismatches, $knownGaps, $consumedGapKeys);
}

function isLaravelAiSource(?string $file): bool
{
    return $file !== null && \str_contains(\str_replace('\\', '/', $file), '/vendor/laravel/ai/');
}

function installedLaravelAiVersion(): ?string
{
    if (!\class_exists(\Composer\InstalledVersions::class) || !\Composer\InstalledVersions::isInstalled('laravel/ai')) {
        return null;
    }

    $version = \Composer\InstalledVersions::getPrettyVersion('laravel/ai');

    return $version !== null ? \ltrim($version, 'v') : null;
}

/**
 * Only a plain dotted-numeric version (`0.11.0`, `1.2`) is comparable against
 * an `@since` tag. A branch alias or dev version (`dev-master`, `0.x-dev`)
 * sorts unpredictably under version_compare(), so treat those as "not
 * gateable" rather than risk silently exempting a method that a bleeding-edge
 * install is expected to have.
 */
function isPatchVersion(string $version): bool
{
    return \preg_match('/^\d+(\.\d+){1,3}$/', $version) === 1;
}

/**
 * @param list<string> $versionGated
 */
function versionGateApplies(?\PhpParser\Comment\Doc $docComment, ?string $installedVersion, string $key, array &$versionGated): bool
{
    $since = sinceTag($docComment);

    if ($since === null || $installedVersion === null || !isPatchVersion($since) || !isPatchVersion($installedVersion)) {
        return false;
    }

    if (\version_compare($installedVersion, $since, '<')) {
        $versionGated[] = "{$key}() (@since {$since})";

        return true;
    }

    return false;
}

function sinceTag(?\PhpParser\Comment\Doc $docComment): ?string
{
    if ($docComment === null || \preg_match('/@since\s+(\S+)/', $docComment->getText(), $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

function traitProvidesConcreteMethod(Node\Stmt\ClassLike $classLike, string $methodName): bool
{
    foreach (stubTraits($classLike) as $trait) {
        if (!\trait_exists($trait)) {
            continue;
        }

        $reflectionClass = new \ReflectionClass($trait);
        if ($reflectionClass->hasMethod($methodName) && !$reflectionClass->getMethod($methodName)->isAbstract()) {
            return true;
        }
    }

    return false;
}

function traitProvidesProperty(Node\Stmt\ClassLike $classLike, string $propertyName): bool
{
    foreach (stubTraits($classLike) as $trait) {
        if (\trait_exists($trait) && (new \ReflectionClass($trait))->hasProperty($propertyName)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function stubTraits(Node\Stmt\ClassLike $classLike): array
{
    $traits = [];
    foreach ($classLike->stmts ?? [] as $statement) {
        if (!$statement instanceof Node\Stmt\TraitUse) {
            continue;
        }

        foreach ($statement->traits as $trait) {
            $traits[] = $trait->toString();
        }
    }

    return $traits;
}

/** @return array<string, true> */
function declaredInterfaceNames(Node\Stmt\ClassLike $classLike): array
{
    $interfaces = [];
    $names = match (true) {
        $classLike instanceof Node\Stmt\Class_, $classLike instanceof Node\Stmt\Enum_ => $classLike->implements,
        $classLike instanceof Node\Stmt\Interface_ => $classLike->extends,
        default => [],
    };

    foreach ($names as $name) {
        $interfaces[$name->toString()] = true;
    }

    return $interfaces;
}

/**
 * Each literal name expanded to include the interfaces it itself extends
 * (e.g. `IteratorAggregate` implies `Traversable`), matching what Reflection
 * reports for a real `implements IteratorAggregate` — Reflection can't tell
 * the difference between a name explicitly written and one only reachable
 * through it.
 *
 * @return array<string, true>
 */
function declaredInterfaceClosure(Node\Stmt\ClassLike $classLike): array
{
    $closure = declaredInterfaceNames($classLike);

    foreach (\array_keys($closure) as $interfaceName) {
        if (\interface_exists($interfaceName)) {
            foreach ((new \ReflectionClass($interfaceName))->getInterfaceNames() as $inherited) {
                $closure[$inherited] = true;
            }
        }
    }

    return $closure;
}

/** @return array<string, true> */
function declaredPropertyNames(Node\Stmt\ClassLike $classLike): array
{
    $properties = [];
    foreach ($classLike->stmts ?? [] as $statement) {
        if ($statement instanceof Node\Stmt\Property) {
            foreach ($statement->props as $property) {
                $properties[$property->name->toString()] = true;
            }
        }

        if ($statement instanceof Node\Stmt\ClassMethod && $statement->name->toString() === '__construct') {
            foreach ($statement->params as $parameter) {
                if (($parameter->flags & (Node\Stmt\Class_::MODIFIER_PUBLIC | Node\Stmt\Class_::MODIFIER_PROTECTED | Node\Stmt\Class_::MODIFIER_PRIVATE)) !== 0
                    && $parameter->var instanceof Node\Expr\Variable
                    && \is_string($parameter->var->name)) {
                    $properties[$parameter->var->name] = true;
                }
            }
        }
    }

    return $properties;
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
    bool $stubReturnsReference,
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

    if (\count($stubParams) !== \count($reflectedParams)) {
        report(
            $label,
            "{$label}(): stub declares " . \count($stubParams) . ' parameter(s) (' . paramNameList($stubParams)
                . '), installed laravel/ai declares ' . \count($reflectedParams) . ' (' . reflectedParamNameList($reflectedParams) . ')',
            $mismatches,
            $knownGaps,
            $consumedGapKeys,
        );
    }

    foreach ($stubParams as $position => $stubParam) {
        if (isset($reflectedParams[$position])) {
            $stubParamName = paramName($stubParam);
            $vendorParamName = $reflectedParams[$position]->getName();

            // Names are API: `@psalm-taint-sink llm_prompt $prompt` matches by
            // name, and named arguments bind by name, so a rename that keeps
            // the type is a silent break in both directions.
            if ($stubParamName !== null && $stubParamName !== $vendorParamName) {
                report(
                    $label,
                    "{$label}(): parameter at position {$position} is named \"\${$stubParamName}\" in the stub, \"\${$vendorParamName}\" in the installed laravel/ai",
                    $mismatches,
                    $knownGaps,
                    $consumedGapKeys,
                );
            }
        }

        $vendorParamType = isset($reflectedParams[$position]) ? $reflectedParams[$position]->getType() : null;

        if (!isset($reflectedParams[$position])) {
            continue;
        }

        if ($stubParam->byRef !== $reflectedParams[$position]->isPassedByReference()) {
            report(
                $label,
                "{$label}(): parameter at position {$position} by-reference metadata differs (stub: "
                    . ($stubParam->byRef ? 'by-reference' : 'by-value') . ', installed laravel/ai: '
                    . ($reflectedParams[$position]->isPassedByReference() ? 'by-reference' : 'by-value') . ')',
                $mismatches,
                $knownGaps,
                $consumedGapKeys,
            );
        }

        if ($stubParam->variadic !== $reflectedParams[$position]->isVariadic()) {
            report(
                $label,
                "{$label}(): parameter at position {$position} variadic metadata differs (stub: "
                    . ($stubParam->variadic ? 'variadic' : 'non-variadic') . ', installed laravel/ai: '
                    . ($reflectedParams[$position]->isVariadic() ? 'variadic' : 'non-variadic') . ')',
                $mismatches,
                $knownGaps,
                $consumedGapKeys,
            );
        }

        $stubHasDefault = $stubParam->default !== null;
        $vendorHasDefault = $reflectedParams[$position]->isDefaultValueAvailable();
        if ($stubHasDefault !== $vendorHasDefault
            || ($stubHasDefault && $vendorHasDefault && stubDefaultToString($stubParam->default) !== reflectionDefaultToString($reflectedParams[$position]))) {
            report(
                $label,
                "{$label}(): parameter at position {$position} default/optionality differs (stub: "
                    . ($stubHasDefault ? stubDefaultToString($stubParam->default) : 'required')
                    . ', installed laravel/ai: ' . ($vendorHasDefault ? reflectionDefaultToString($reflectedParams[$position]) : 'required') . ')',
                $mismatches,
                $knownGaps,
                $consumedGapKeys,
            );
        }

        $vendorParamType = $reflectedParams[$position]->getType();
        if ($stubParam->type === null || $vendorParamType === null) {
            // Psalm stubs often spell an untyped vendor parameter as `mixed`
            // for useful local analysis. PHP reflection reports that as no
            // native type, so treat it as equivalent while still catching a
            // concrete type introduced on only one side.
            $stubIsEffectivelyUntyped = $stubParam->type instanceof Node\Identifier
                && \strtolower($stubParam->type->toString()) === 'mixed';
            if ($stubIsEffectivelyUntyped && $vendorParamType === null) {
                continue;
            }
            if ($stubParam->type !== null || $vendorParamType !== null) {
                report(
                    $label,
                    "{$label}(): parameter at position {$position} has a native type on only one side (stub: "
                        . ($stubParam->type === null ? 'none' : stubTypeToString($stubParam->type, $stubParam->default, $enclosingFqcn))
                        . ', installed laravel/ai: ' . ($vendorParamType === null ? 'none' : reflectionTypeToString($vendorParamType, $declaringFqcn)) . ')',
                    $mismatches,
                    $knownGaps,
                    $consumedGapKeys,
                );
            }

            continue;
        }

        $stubType = stubTypeToString($stubParam->type, $stubParam->default, $enclosingFqcn);
        $vendorType = reflectionTypeToString($vendorParamType, $declaringFqcn);

        if ($stubType !== $vendorType) {
            $stubParamName = paramName($stubParam);
            $described = $stubParamName !== null ? '$' . $stubParamName : "position {$position}";
            report($label, "{$label}({$described}): stub says \"{$stubType}\", installed laravel/ai says \"{$vendorType}\"", $mismatches, $knownGaps, $consumedGapKeys);
        }
    }

    if ($stubReturnsReference !== $reflected->returnsReference()) {
        report(
            $label,
            "{$label}(): return-by-reference metadata differs (stub: "
                . ($stubReturnsReference ? 'by-reference' : 'by-value') . ', installed laravel/ai: '
                . ($reflected->returnsReference() ? 'by-reference' : 'by-value') . ')',
            $mismatches,
            $knownGaps,
            $consumedGapKeys,
        );
    }

    if ($stubReturnType !== null) {
        $vendorReturnType = $reflected->getReturnType();
        if ($vendorReturnType === null) {
            // Docblock/native precision on an untyped framework trait method
            // is an intentional Psalm enhancement, not vendor drift.
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
 * Null for a destructuring or otherwise non-plain parameter variable, which
 * php-parser models as an arbitrary expression.
 */
function paramName(Node\Param $param): ?string
{
    return $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)
        ? $param->var->name
        : null;
}

/** @param list<Node\Param> $params */
function paramNameList(array $params): string
{
    if ($params === []) {
        return 'none';
    }

    return \implode(', ', \array_map(
        static fn(Node\Param $param): string => '$' . (paramName($param) ?? '?'),
        $params,
    ));
}

/** @param list<\ReflectionParameter> $params */
function reflectedParamNameList(array $params): string
{
    if ($params === []) {
        return 'none';
    }

    return \implode(', ', \array_map(
        static fn(\ReflectionParameter $param): string => '$' . $param->getName(),
        $params,
    ));
}

function stubDefaultToString(?Node\Expr $default): string
{
    if ($default === null) {
        return 'required';
    }

    if ($default instanceof Node\Expr\Array_ && $default->items === []) {
        return 'array()';
    }

    return \strtolower((new Standard())->prettyPrintExpr($default)) === 'null'
        ? 'null'
        : (new Standard())->prettyPrintExpr($default);
}

function reflectionDefaultToString(\ReflectionParameter $parameter): string
{
    if ($parameter->isDefaultValueConstant()) {
        return (string) $parameter->getDefaultValueConstantName();
    }

    $value = $parameter->getDefaultValue();
    if ($value === null) {
        return 'null';
    }
    if ($value === true) {
        return 'true';
    }
    if ($value === false) {
        return 'false';
    }
    if (\is_array($value) && $value === []) {
        return 'array()';
    }

    return \var_export($value, true);
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
