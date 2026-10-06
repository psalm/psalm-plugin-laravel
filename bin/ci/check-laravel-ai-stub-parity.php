<?php

declare(strict_types=1);

/**
 * Diffs every re-declared laravel/ai stub signature against the installed package via reflection.
 *
 * A registered stub overrides vendor reflection, so Psalm never notices when a stub's native signature stops matching
 * a newer laravel/ai release, and every type test (which runs against the stub) stays green. Stubs are parsed with
 * php-parser, never loaded: redeclaring a class the vendor autoloader provides would fatal.
 *
 * What is compared, how `@since` gating and `@stub-waive` waivers work: "Stub merging" in docs/contributing/README.md.
 *
 * Usage: php bin/ci/check-laravel-ai-stub-parity.php [stubs-dir]   (default: stubs/integrations/laravel-ai)
 * Exit codes: 0 = no drift (beyond `@stub-waive`d findings), 1 = drift or a stubbed class/method missing from the
 *             installed package, 2 = laravel/ai not installed (soft skip; the calling CI leg gates on that itself).
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!\class_exists(\Laravel\Ai\AnonymousAgent::class)) {
    echo "laravel/ai is not installed; nothing to compare.\n";
    exit(2);
}

$stubDir = $argv[1] ?? dirname(__DIR__, 2) . '/stubs/integrations/laravel-ai';
$findings = new Findings(installedLaravelAiVersion());
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$finder = new NodeFinder();

foreach (findStubFiles($stubDir) as $file) {
    $ast = $parser->parse(\file_get_contents($file) ?: '');
    if ($ast === null) {
        $findings->report($file, "{$file}: php-parser could not parse this stub");
        continue;
    }

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver());
    $ast = $traverser->traverse($ast);

    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $classLike) {
        compareClassLike($findings, $classLike, $file);
    }

    foreach ($finder->findInstanceOf($ast, Node\Stmt\Function_::class) as $function) {
        $fqcn = $function->namespacedName?->toString();
        if ($fqcn === null) {
            continue;
        }

        $findings->registerWaivers($function->getDocComment(), $fqcn, $fqcn);

        if (!\function_exists($fqcn)) {
            $findings->report($fqcn, "{$fqcn}(): declared in {$file} but not found in the installed laravel/ai package (renamed or removed upstream?)");
            continue;
        }

        $findings->comparedSignatures++;
        diffSignature($findings, $fqcn, $function, new \ReflectionFunction($fqcn), null);
    }
}

echo "Compared {$findings->comparedSignatures} method/function signatures across {$findings->comparedClasses} classes against the installed laravel/ai package.\n";

printSection('Waived by @stub-waive in the stub (still reported, not fatal)', $findings->waived);
printSection("Version-gated (installed laravel/ai {$findings->installedVersion} predates the stub declaration's @since tag)", $findings->gated);

$staleWaivers = $findings->staleWaivers();
if ($staleWaivers !== []) {
    echo "\n";
    foreach ($staleWaivers as $staleWaiver) {
        echo "::warning::{$staleWaiver} no longer matches any finding. Remove it.\n";
    }
}

if ($findings->drift !== []) {
    printSection('Signature drift detected', $findings->drift);
    exit(1);
}

exit(0);

final class Findings
{
    /** @var list<string> */
    public array $drift = [];

    /** @var list<string> */
    public array $waived = [];

    /** @var list<string> */
    public array $gated = [];

    public int $comparedSignatures = 0;

    public int $comparedClasses = 0;

    /** @var array<string, array{reason: string, tag: string, used: bool}> */
    private array $waivers = [];

    public function __construct(public readonly ?string $installedVersion) {}

    /** A finding on a member the stub DECLARES (or on a class/function): waived only by that element's own docblock. */
    public function report(string $key, string $message): void
    {
        $this->settle("drift {$key}", $message);
    }

    /** A real member or interface the stub OMITS: waived only by a class-docblock `@stub-waive` naming it. */
    public function reportOmission(string $key, string $message): void
    {
        $this->settle("omit {$key}", $message);
    }

    private function settle(string $waiverKey, string $message): void
    {
        if (!isset($this->waivers[$waiverKey])) {
            $this->drift[] = $message;

            return;
        }

        $this->waivers[$waiverKey]['used'] = true;
        $this->waived[] = "{$message} (@stub-waive: {$this->waivers[$waiverKey]['reason']})";
    }

    /**
     * Records the `@stub-waive` tags of one docblock. In a class docblock every tag names an omitted member
     * (`name()`, `$name`, `implements \Fqcn`); in a member (or function) docblock a bare tag waives drift of that
     * declaration's own signature, `$memberKey` being its finding key. The two never cross: a class-level `foo()`
     * must not also mute drift on a `foo()` the stub does declare, and a tag that does not fit its position, or has
     * no reason, is itself drift rather than a silent mute.
     */
    public function registerWaivers(?\PhpParser\Comment\Doc $doc, string $fqcn, ?string $memberKey = null): void
    {
        $at = $memberKey ?? $fqcn;

        foreach (waiverTags($doc) as $target => $reason) {
            $tag = \rtrim("@stub-waive {$target}");

            if ($reason === '') {
                $this->drift[] = "{$at}: `{$tag}` has no reason; a waiver must say why the member is safe to leave unrestated";
                continue;
            }

            $waiverKey = match (true) {
                $memberKey !== null && $target === '' => "drift {$memberKey}",
                $memberKey !== null || $target === '' => null,
                \str_starts_with($target, '$') => "omit {$fqcn}::{$target}",
                \str_ends_with($target, '()') => "omit {$fqcn}::" . \substr($target, 0, -2),
                default => "omit {$fqcn} {$target}",
            };

            if ($waiverKey === null) {
                $this->drift[] = $memberKey !== null
                    ? "{$at}: `{$tag}` names a target, but a member docblock waives only its own signature (write `@stub-waive <reason>`; omitted members are waived in the class docblock)"
                    : "{$at}: `{$tag}` needs a target (`name()`, `\$name` or `implements \\Fqcn`) in a class docblock";
                continue;
            }

            $this->waivers[$waiverKey] ??= ['reason' => $reason, 'tag' => "`{$tag}` on {$at}", 'used' => false];
        }
    }

    /** @return list<string> waivers that waived nothing: the member reappeared in the stub or vanished upstream */
    public function staleWaivers(): array
    {
        $stale = [];
        foreach ($this->waivers as $waiver) {
            if (!$waiver['used']) {
                $stale[] = "Waiver {$waiver['tag']}";
            }
        }

        return $stale;
    }

    /**
     * Whether a `@since` tag in `$doc` exempts the element being checked, recording it as version-gated if so.
     * `$target` selects the tag: '' for the documented element itself (method or class), or an interface FQCN
     * for a class docblock's `@since X implements \Fqcn` line.
     *
     * Exempts only while the installed version is strictly OLDER than the tag, and only when both are plain
     * dotted-numeric versions: a `dev-master`/`1.x-dev` install sorts unpredictably under version_compare(),
     * so it falls through to the normal check instead of being silently exempted.
     */
    public function gatedBySince(?\PhpParser\Comment\Doc $doc, string $label, string $target = ''): bool
    {
        $since = sinceTags($doc)[$target] ?? null;
        if ($since === null
            || $this->installedVersion === null
            || !isDottedVersion($since)
            || !isDottedVersion($this->installedVersion)
            || !\version_compare($this->installedVersion, $since, '<')) {
            return false;
        }

        $this->gated[] = "{$label} (@since {$since})";

        return true;
    }
}

/** @param list<string> $lines */
function printSection(string $heading, array $lines): void
{
    if ($lines === []) {
        return;
    }

    echo "\n{$heading}:\n";
    foreach ($lines as $line) {
        echo " - {$line}\n";
    }
}

function installedLaravelAiVersion(): ?string
{
    if (!\class_exists(\Composer\InstalledVersions::class) || !\Composer\InstalledVersions::isInstalled('laravel/ai')) {
        return null;
    }

    $version = \Composer\InstalledVersions::getPrettyVersion('laravel/ai');

    return $version !== null ? \ltrim($version, 'v') : null;
}

function isDottedVersion(string $version): bool
{
    return \preg_match('/^\d+(\.\d+){1,3}$/', $version) === 1;
}

/**
 * Parses every `@since X.Y.Z [implements|extends \Fqcn]` line of a docblock. Without a clause the tag is for the
 * documented element itself (key ''); with one it is for that interface, which has no docblock of its own.
 *
 * @return array<string, string> target => version, first tag wins
 */
function sinceTags(?\PhpParser\Comment\Doc $doc): array
{
    if ($doc === null
        || \preg_match_all('/@since[ \t]+(\S+)(?:[ \t]+(?:implements|extends)[ \t]+\\\\?([A-Za-z_][\w\\\\]*))?/', $doc->getText(), $matches, \PREG_SET_ORDER) < 1) {
        return [];
    }

    $tags = [];
    foreach ($matches as $match) {
        $tags[$match[2] ?? ''] ??= $match[1];
    }

    return $tags;
}

/**
 * Parses every `@stub-waive [target] reason` line of a docblock, the `@since` sibling for a member the stub
 * deliberately leaves out (or, on a declared member, deliberately lets drift). The target is `name()`, `$name` or
 * `implements \Fqcn` (`extends` accepted and normalized, no leading backslash), or absent for the documented
 * element itself (key ''). The reason is the rest of the line; an empty one is returned as '' so the caller can
 * reject it.
 *
 * @return array<string, string> target => reason, first tag wins
 */
function waiverTags(?\PhpParser\Comment\Doc $doc): array
{
    if ($doc === null
        || \preg_match_all('~@stub-waive(?![\w-])(?:[ \t]+(\w+\(\)|\$\w+|(?:implements|extends)[ \t]+\\\\?[A-Za-z_][\w\\\\]*)(?=[ \t\r\n]|\*/|$))?[ \t]*([^\r\n]*)~m', $doc->getText(), $matches, \PREG_SET_ORDER) < 1) {
        return [];
    }

    $tags = [];
    foreach ($matches as $match) {
        $target = \preg_replace('/^(?:implements|extends)[ \t]+\\\\?/', 'implements ', $match[1]) ?? $match[1];
        $tags[$target] ??= \trim(\preg_replace('~\s*\*/\s*$~', '', $match[2]) ?? $match[2]);
    }

    return $tags;
}

function compareClassLike(Findings $findings, Node\Stmt\ClassLike $classLike, string $file): void
{
    $fqcn = $classLike->namespacedName?->toString();
    if ($fqcn === null) {
        return;
    }

    if (!\class_exists($fqcn) && !\interface_exists($fqcn) && !\trait_exists($fqcn)) {
        // A gated class is skipped before any reflection and stays out of the compared counters: nothing was
        // compared, and the summary must not claim coverage the run didn't give.
        if (!$findings->gatedBySince($classLike->getDocComment(), $fqcn)) {
            $findings->report($fqcn, "{$fqcn}: declared in {$file} but not found in the installed laravel/ai package (renamed or removed upstream?)");
        }

        return;
    }

    $findings->comparedClasses++;
    $reflectionClass = new \ReflectionClass($fqcn);
    $findings->registerWaivers($classLike->getDocComment(), $fqcn);

    $declaredMethodNames = [];
    foreach ($classLike->getMethods() as $method) {
        $methodName = $method->name->toString();
        $declaredMethodNames[$methodName] = true;
        $key = "{$fqcn}::{$methodName}";
        $findings->registerWaivers($method->getDocComment(), $fqcn, $key);

        if (!$reflectionClass->hasMethod($methodName)) {
            if (!$findings->gatedBySince($method->getDocComment(), "{$key}()")) {
                $findings->report($key, "{$key}(): declared in the stub but not found on the installed class (renamed or removed upstream?)");
            }

            continue;
        }

        $findings->comparedSignatures++;
        diffSignature($findings, $key, $method, $reflectionClass->getMethod($methodName), $fqcn);
    }

    compareOmittedMembers($findings, $classLike, $reflectionClass, $declaredMethodNames);
    compareInterfaces($findings, $classLike, $reflectionClass, isset($declaredMethodNames['__toString']));
}

/**
 * Psalm merges a member the stub omits in from the real class, so this is a taint-review tripwire rather than a
 * correctness check: the merged-in member carries none of the stub's taint annotations. Only laravel/ai's own
 * members count (not framework trait helpers like SerializesModels), and a trait the stub `use`s counts as
 * providing a member only when it implements it concretely.
 *
 * @param array<string, true> $declaredMethodNames
 */
function compareOmittedMembers(Findings $findings, Node\Stmt\ClassLike $classLike, \ReflectionClass $reflectionClass, array $declaredMethodNames): void
{
    $fqcn = $reflectionClass->getName();
    $traits = [];
    foreach ($classLike->getTraitUses() as $traitUse) {
        foreach ($traitUse->traits as $trait) {
            if (\trait_exists($trait->toString())) {
                $traits[] = new \ReflectionClass($trait->toString());
            }
        }
    }

    foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
        $name = $method->getName();
        if ($method->getDeclaringClass()->getName() !== $fqcn
            || !isLaravelAiSource($method->getFileName())
            || isset($declaredMethodNames[$name])) {
            continue;
        }

        foreach ($traits as $trait) {
            if ($trait->hasMethod($name) && !$trait->getMethod($name)->isAbstract()) {
                continue 2;
            }
        }

        $visibility = $method->isProtected() ? 'protected' : 'public';
        $findings->reportOmission("{$fqcn}::{$name}", "{$fqcn}::{$name}(): {$visibility} method exists in installed laravel/ai but is missing from the stub");
    }

    $declaredPropertyNames = declaredPropertyNames($classLike);
    foreach ($reflectionClass->getProperties() as $property) {
        $name = $property->getName();
        if ($property->getDeclaringClass()->getName() !== $fqcn
            || $property->isPrivate()
            || !isLaravelAiSource($property->getDeclaringClass()->getFileName())
            || isset($declaredPropertyNames[$name])) {
            continue;
        }

        foreach ($traits as $trait) {
            if ($trait->hasProperty($name)) {
                continue 2;
            }
        }

        $findings->reportOmission("{$fqcn}::\${$name}", "{$fqcn}::\${$name}: public/protected property exists in installed laravel/ai but is missing from the stub");
    }
}

/**
 * Unlike methods/properties, the interface list IS wiped by a redeclaration (`parent_classes` is not, so
 * interfaces inherited from the real parent need no repeating). Checked both ways: an interface the stub omits,
 * and a stale one it still declares.
 */
function compareInterfaces(Findings $findings, Node\Stmt\ClassLike $classLike, \ReflectionClass $reflectionClass, bool $hasToString): void
{
    $fqcn = $reflectionClass->getName();
    $clauseWord = $classLike instanceof Node\Stmt\Interface_ ? 'extends' : 'implements';

    $declared = declaredInterfaceNames($classLike);

    // Reflection can't tell a written name from one only reachable through it (`IteratorAggregate` implies
    // `Traversable`), and PHP grants `Stringable` to any class with `__toString()`, so both count as declared.
    $implied = $declared;
    foreach (\array_keys($declared) as $interfaceName) {
        if (\interface_exists($interfaceName)) {
            foreach ((new \ReflectionClass($interfaceName))->getInterfaceNames() as $inherited) {
                $implied[$inherited] = true;
            }
        }
    }

    if ($hasToString) {
        $implied['Stringable'] = true;
    }

    $parentClass = $reflectionClass->getParentClass();
    $inherited = $parentClass !== false ? \array_flip($parentClass->getInterfaceNames()) : [];
    $real = $reflectionClass->getInterfaceNames();

    foreach ($real as $interfaceName) {
        if (!isset($implied[$interfaceName]) && !isset($inherited[$interfaceName])) {
            $findings->reportOmission(
                "{$fqcn} implements {$interfaceName}",
                "{$fqcn}: implements {$interfaceName} in the installed laravel/ai, but the stub's `{$clauseWord}` clause omits it (Psalm wipes the interface list on redeclaration)",
            );
        }
    }

    $realSet = \array_flip($real);
    foreach (\array_keys($declared) as $interfaceName) {
        if (isset($realSet[$interfaceName])
            || $findings->gatedBySince($classLike->getDocComment(), "{$fqcn} {$clauseWord} {$interfaceName}", $interfaceName)) {
            continue;
        }

        $findings->report(
            "{$fqcn} implements {$interfaceName} (stale)",
            "{$fqcn}: stub's `{$clauseWord}` clause declares {$interfaceName}, but the installed class doesn't implement it (renamed or removed upstream?)",
        );
    }
}

function isLaravelAiSource(string|false|null $file): bool
{
    return \is_string($file) && \str_contains(\str_replace('\\', '/', $file), '/vendor/laravel/ai/');
}

/** @return array<string, true> */
function declaredInterfaceNames(Node\Stmt\ClassLike $classLike): array
{
    $names = match (true) {
        $classLike instanceof Node\Stmt\Class_, $classLike instanceof Node\Stmt\Enum_ => $classLike->implements,
        $classLike instanceof Node\Stmt\Interface_ => $classLike->extends,
        default => [],
    };

    $interfaces = [];
    foreach ($names as $name) {
        $interfaces[$name->toString()] = true;
    }

    return $interfaces;
}

/** @return array<string, true> */
function declaredPropertyNames(Node\Stmt\ClassLike $classLike): array
{
    $properties = [];
    foreach ($classLike->getProperties() as $property) {
        foreach ($property->props as $item) {
            $properties[$item->name->toString()] = true;
        }
    }

    foreach ($classLike->getMethod('__construct')?->params ?? [] as $parameter) {
        $name = paramName($parameter);
        if ($parameter->isPromoted() && $name !== null) {
            $properties[$name] = true;
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

function flagDifference(string $on, string $off, bool $stub, bool $vendor): string
{
    return '(stub: ' . ($stub ? $on : $off) . ', installed laravel/ai: ' . ($vendor ? $on : $off) . ')';
}

/** @param Node\Stmt\ClassMethod|Node\Stmt\Function_ $stub */
function diffSignature(Findings $findings, string $label, Node\FunctionLike $stub, \ReflectionFunctionAbstract $reflected, ?string $enclosingFqcn): void
{
    // Plain functions have no `self`, and ReflectionFunction has no getDeclaringClass().
    $declaringFqcn = $reflected instanceof \ReflectionMethod ? $reflected->getDeclaringClass()->getName() : null;
    $fail = static fn(string $message, string $scope = '') => $findings->report($label, "{$label}({$scope}): {$message}");

    $stubParams = $stub->getParams();
    $vendorParams = $reflected->getParameters();

    if (\count($stubParams) !== \count($vendorParams)) {
        $fail(
            'stub declares ' . \count($stubParams) . ' parameter(s) (' . parameterNames(\array_map(paramName(...), $stubParams))
            . '), installed laravel/ai declares ' . \count($vendorParams) . ' ('
            . parameterNames(\array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $vendorParams)) . ')',
        );
    }

    foreach ($stubParams as $position => $stubParam) {
        $vendorParam = $vendorParams[$position] ?? null;
        if ($vendorParam === null) {
            continue;
        }

        $at = "parameter at position {$position}";
        $stubName = paramName($stubParam);

        // Names are API: `@psalm-taint-sink llm_prompt $prompt` matches by name, and named arguments bind by
        // name, so a rename that keeps the type silently disarms the sink.
        if ($stubName !== null && $stubName !== $vendorParam->getName()) {
            $fail("{$at} is named \"\${$stubName}\" in the stub, \"\${$vendorParam->getName()}\" in the installed laravel/ai");
        }

        if ($stubParam->byRef !== $vendorParam->isPassedByReference()) {
            $fail("{$at} by-reference metadata differs " . flagDifference('by-reference', 'by-value', $stubParam->byRef, $vendorParam->isPassedByReference()));
        }

        if ($stubParam->variadic !== $vendorParam->isVariadic()) {
            $fail("{$at} variadic metadata differs " . flagDifference('variadic', 'non-variadic', $stubParam->variadic, $vendorParam->isVariadic()));
        }

        $stubDefault = $stubParam->default;
        $vendorHasDefault = $vendorParam->isDefaultValueAvailable();
        if (($stubDefault !== null) !== $vendorHasDefault
            || ($stubDefault !== null && !defaultValuesMatch($stubDefault, $vendorParam, $enclosingFqcn))) {
            $fail("{$at} default/optionality differs (stub: " . stubDefaultToString($stubDefault)
                . ', installed laravel/ai: ' . ($vendorHasDefault ? reflectionDefaultToString($vendorParam) : 'required') . ')');
        }

        $vendorType = $vendorParam->getType();
        if ($stubParam->type === null || $vendorType === null) {
            // Stubs often spell an untyped vendor parameter `mixed`; Reflection reports no native type for it.
            $mixedForUntyped = $vendorType === null
                && $stubParam->type instanceof Node\Identifier
                && \strtolower($stubParam->type->toString()) === 'mixed';

            if (($stubParam->type !== null || $vendorType !== null) && !$mixedForUntyped) {
                $fail("{$at} has a native type on only one side (stub: "
                    . ($stubParam->type === null ? 'none' : stubTypeToString($stubParam->type, $stubParam->default, $enclosingFqcn))
                    . ', installed laravel/ai: ' . ($vendorType === null ? 'none' : reflectionTypeToString($vendorType, $declaringFqcn)) . ')');
            }

            continue;
        }

        $stubType = stubTypeToString($stubParam->type, $stubParam->default, $enclosingFqcn);
        $vendorTypeString = reflectionTypeToString($vendorType, $declaringFqcn);
        if ($stubType !== $vendorTypeString) {
            $fail("stub says \"{$stubType}\", installed laravel/ai says \"{$vendorTypeString}\"", $stubName !== null ? '$' . $stubName : "position {$position}");
        }
    }

    if ($stub->returnsByRef() !== $reflected->returnsReference()) {
        $fail('return-by-reference metadata differs ' . flagDifference('by-reference', 'by-value', $stub->returnsByRef(), $reflected->returnsReference()));
    }

    // A stub narrowing an untyped vendor return is an intentional Psalm enhancement, not drift.
    $stubReturnType = $stub->getReturnType();
    $vendorReturnType = $reflected->getReturnType();
    if ($stubReturnType !== null && $vendorReturnType !== null) {
        $stubReturn = stubTypeToString($stubReturnType, null, $enclosingFqcn);
        $vendorReturn = reflectionTypeToString($vendorReturnType, $declaringFqcn);
        if ($stubReturn !== $vendorReturn) {
            $fail("return type stub says \"{$stubReturn}\", installed laravel/ai says \"{$vendorReturn}\"");
        }
    }
}

/** Null for a destructuring or otherwise non-plain parameter variable. */
function paramName(Node\Param $param): ?string
{
    return $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)
        ? $param->var->name
        : null;
}

/** @param list<?string> $names */
function parameterNames(array $names): string
{
    return $names === []
        ? 'none'
        : \implode(', ', \array_map(static fn(?string $name): string => '$' . ($name ?? '?'), $names));
}

function stubDefaultToString(?Node\Expr $default): string
{
    if ($default === null) {
        return 'required';
    }

    if ($default instanceof Node\Expr\Array_ && $default->items === []) {
        return 'array()';
    }

    $source = (new Standard())->prettyPrintExpr($default);

    return \strtolower($source) === 'null' ? 'null' : $source;
}

/**
 * Reflection evaluates a `new` initializer into an object whose state can't be compared to source, so such a
 * default compares by class (and optionality); every other default compares exactly.
 */
function defaultValuesMatch(Node\Expr $stubDefault, \ReflectionParameter $parameter, ?string $enclosingFqcn): bool
{
    $vendorDefault = $parameter->getDefaultValue();
    if (!\is_object($vendorDefault)) {
        return stubDefaultToString($stubDefault) === reflectionDefaultToString($parameter);
    }

    return $stubDefault instanceof Node\Expr\New_
        && $stubDefault->class instanceof Node\Name
        && stubTypeToString($stubDefault->class, null, $enclosingFqcn) === $vendorDefault::class;
}

function reflectionDefaultToString(\ReflectionParameter $parameter): string
{
    if ($parameter->isDefaultValueConstant()) {
        return (string) $parameter->getDefaultValueConstantName();
    }

    $value = $parameter->getDefaultValue();

    return match (true) {
        $value === null => 'null',
        \is_bool($value) => $value ? 'true' : 'false',
        \is_object($value) => 'new ' . $value::class,
        $value === [] => 'array()',
        default => \var_export($value, true),
    };
}

/**
 * `getName()` resolving `self` to the declaring class is PHP-version dependent (resolving only one side reported
 * every fluent `self` method as drift on CI while staying clean locally), so both sides normalize explicitly.
 * `static` stays literal on both.
 */
function resolveRelativeTypeName(string $name, ?string $enclosingFqcn): string
{
    if ($enclosingFqcn === null) {
        return $name;
    }

    return match (\strtolower($name)) {
        'self' => $enclosingFqcn,
        'parent' => (new \ReflectionClass($enclosingFqcn))->getParentClass()?->getName() ?? $name,
        default => $name,
    };
}

/**
 * Native-type-only normalization, comparable against reflectionTypeToString(): docblock precision is
 * deliberately invisible. Reflection expands `iterable` to `Traversable|array` only as a union member, so the
 * same expansion applies here, which keeps a bare `array` distinct from `iterable`.
 */
function stubTypeToString(Node\Identifier|Node\Name|Node\ComplexType $type, ?Node\Expr $default, ?string $enclosingFqcn): string
{
    if ($type instanceof Node\NullableType) {
        return '?' . stubTypeToString($type->type, null, $enclosingFqcn);
    }

    if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
        $parts = [];
        foreach ($type->types as $member) {
            $part = stubTypeToString($member, null, $enclosingFqcn);
            \array_push($parts, ...(\strtolower($part) === 'iterable' ? ['Traversable', 'array'] : [$part]));
        }
        \sort($parts);

        return \implode($type instanceof Node\UnionType ? '|' : '&', $parts);
    }

    $name = resolveRelativeTypeName($type->toString(), $enclosingFqcn);

    // A bare type with an explicit `= null` default is implicitly nullable (deprecated, but Reflection's
    // allowsNull() reports it). `mixed`/`null` already cover null and can't take a `?` prefix.
    $impliedNullable = $default instanceof Node\Expr\ConstFetch
        && \strtolower($default->name->toString()) === 'null'
        && !\in_array(\strtolower($name), ['mixed', 'null'], true);

    return ($impliedNullable ? '?' : '') . $name;
}

function reflectionTypeToString(\ReflectionType $type, ?string $declaringFqcn): string
{
    if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
        $parts = \array_map(
            static fn(\ReflectionType $member): string => reflectionTypeToString($member, $declaringFqcn),
            $type->getTypes(),
        );
        \sort($parts);

        return \implode($type instanceof \ReflectionUnionType ? '|' : '&', $parts);
    }

    \assert($type instanceof \ReflectionNamedType);
    $name = resolveRelativeTypeName($type->getName(), $declaringFqcn);
    $nullable = $type->allowsNull() && !\in_array(\strtolower($name), ['mixed', 'null'], true);

    return ($nullable ? '?' : '') . $name;
}
