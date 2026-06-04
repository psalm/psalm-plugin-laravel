<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util\Ast;

use PhpParser\Comment\Doc;
use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Psalm\Aliases;
use Psalm\DocComment;
use Psalm\Exception\DocblockParseException;
use Psalm\Exception\TypeParseTreeException;
use Psalm\Internal\Analyzer\CommentAnalyzer;
use Psalm\Internal\Type\TypeParser;
use Psalm\Internal\Type\TypeTokenizer;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Union;

/**
 * Build a {@see TClosure} description for a runtime closure object.
 *
 * Mirrors PHPStan's {@see \PHPStan\Type\ClosureTypeFactory} (consumed by
 * Larastan's `MacroMethodsClassReflectionExtension`): given a `\Closure`,
 * return a fully-typed closure description with parameter + return Unions.
 * Psalm exposes no equivalent core service, so this factory plays the same
 * role inside the plugin.
 *
 * Coverage today (post-issue #991): native reflection signature + docblock
 * `@param` / `@return` narrowing extracted from the closure's source file via
 * `nikic/php-parser`. The factory reads the source on demand, so closures
 * registered from `vendor/` packages outside `<projectFiles>` work the same
 * as project-source closures.
 *
 * Coverage extended in PR #994: body-flow inference for the narrow subset of
 * `return` expressions whose static value is obvious without any semantic
 * analysis — literal scalars (`'x'`, `42`, `1.5`, `true`/`false`/`null`),
 * concatenations of inferable strings, arithmetic of inferable numerics, and
 * shaped arrays whose keys and values are themselves inferable. Any unhandled
 * node anywhere in the closure body bails the entire inference. This is
 * deliberately narrower than PHPStan's equivalent: we never trigger Psalm's
 * `StatementsAnalyzer` (would re-enter the scanner mid-scan) and never follow
 * variable references — both are explicitly out of scope for the PR.
 *
 * Stateless by design. Caching is a separate concern handled by
 * {@see CachedClosureTypeFactory}, which composes via callable injection at
 * {@see self::buildWithIndexer()}.
 *
 * @internal
 */
final class ClosureTypeFactory
{
    /**
     * Build a {@see TClosure} from a runtime closure object.
     *
     * Public shape matches PHPStan's `ClosureTypeFactory::fromClosureObject()`:
     * caller hands over a `\Closure`, gets back a typed closure description
     * (params + return) or `null` when no usable source-level information is
     * recoverable.
     *
     * Returns `null` when:
     * - reflection lacks a file location (internal closures, runtime-generated
     *   sources with synthetic paths);
     * - the source file is unreadable or fails to parse;
     * - no closure starts at the reflected line, or multiple closures share
     *   the start line (ambiguity → bail rather than guess);
     * - neither docblock recovery nor PR #994 body-flow inference can add
     *   anything beyond what reflection already exposes. Caller falls back to
     *   a reflection-only pseudo-method in that case.
     *
     * @psalm-api Public convenience for callers that opt out of the memoizing
     *            wrapper (notably the unit tests). Production callers go
     *            through {@see CachedClosureTypeFactory::fromClosureObject()},
     *            which delegates via {@see self::buildWithIndexer()}.
     */
    public static function fromClosureObject(\Closure $closure): ?TClosure
    {
        return self::buildWithIndexer($closure, self::indexFile(...));
    }

    /**
     * Decorator-friendly variant: accepts an external `(realpath): ?index`
     * callable so wrappers like {@see CachedClosureTypeFactory} can substitute
     * a memoized indexer without re-implementing the reflection → realpath →
     * line-lookup → build pipeline.
     *
     * The default indexer (used by {@see self::fromClosureObject()}) is
     * {@see self::indexFile()}, which re-parses on every call. Caching belongs
     * to wrappers, not to this class.
     *
     * @internal Decorator injection seam.
     * @param callable(string): ?array<int, list<array{0: ?Doc, 1: Aliases, 2: Node\Expr\Closure|Node\Expr\ArrowFunction}>> $indexer
     */
    public static function buildWithIndexer(\Closure $closure, callable $indexer): ?TClosure
    {
        $reflection = new \ReflectionFunction($closure);

        $recovered = self::recoverFromSource($reflection, $indexer);

        return self::buildClosureType($reflection, $recovered);
    }

    /**
     * Parse a source file once and return a per-start-line index of every
     * `Closure` / `ArrowFunction` node along with its attached docblock and
     * the namespace + `use` aliases visible at that point.
     *
     * No caching: every call re-parses. Wrap with
     * {@see CachedClosureTypeFactory} (or any compatible memoizer) for
     * repeated lookups against the same file.
     *
     * @return array<int, list<array{0: ?Doc, 1: Aliases, 2: Node\Expr\Closure|Node\Expr\ArrowFunction}>>|null
     */
    public static function indexFile(string $realpath): ?array
    {
        $contents = @\file_get_contents($realpath);
        if ($contents === false) {
            return null;
        }

        try {
            // `createForHostVersion()` matches the runtime PHP version, which
            // produced the reflected closure in the first place — so any
            // feature the file uses is necessarily supported by the host's
            // parser flavor.
            $parser = (new ParserFactory())->createForHostVersion();
            $ast = $parser->parse($contents);
        } catch (PhpParserError) {
            return null;
        }

        if (!\is_array($ast)) {
            return null;
        }

        $visitor = new ClosureDocblockIndexVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getEntries();
    }

    /**
     * Resolve the closure's source entry: the parsed AST node, plus its
     * docblock-derived narrowing when present. Returns `null` only when the
     * indexer cannot uniquely identify a closure at the reflected line — in
     * every other case we surface the node so the body-inference path can
     * still run when the docblock is silent.
     *
     * @param callable(string): ?array<int, list<array{0: ?Doc, 1: Aliases, 2: Node\Expr\Closure|Node\Expr\ArrowFunction}>> $indexer
     * @return array{docblock: ?array{params: array<string, Union>, return: ?Union}, node: Node\Expr\Closure|Node\Expr\ArrowFunction}|null
     */
    private static function recoverFromSource(\ReflectionFunctionAbstract $reflection, callable $indexer): ?array
    {
        $filePath = $reflection->getFileName();
        $line = $reflection->getStartLine();
        if (!\is_string($filePath) || !\is_int($line)) {
            return null;
        }

        // `realpath()` already returns false for synthetic paths produced by
        // runtime code generation and for removed-file edge cases — no
        // separate guard needed.
        $resolved = \realpath($filePath);
        if (!\is_string($resolved) || !\is_readable($resolved)) {
            return null;
        }

        $entries = $indexer($resolved);
        if ($entries === null) {
            return null;
        }

        $matches = $entries[$line] ?? [];
        // Mirror the storage-recovery path in MacroRegistry: bail on
        // ambiguity rather than pick wrong. Two closures starting on the
        // same line is rare in vendor code but possible (e.g. inline
        // `[fn() => 1, fn() => 2]`).
        if (\count($matches) !== 1) {
            return null;
        }

        [$doc, $aliases, $node] = $matches[0];
        $docblock = $doc instanceof Doc ? self::extractDocblock($doc, $aliases) : null;

        return ['docblock' => $docblock, 'node' => $node];
    }

    /**
     * Build the final {@see TClosure}: reflection-derived parameter list with
     * docblock narrowing per parameter, plus the best available return type.
     *
     * Return-type precedence:
     * 1. Docblock `@return` (issue #991 — wins because it can express types
     *    PHP cannot, like `non-empty-string` or `Collection<int, string>`).
     * 2. Native reflected return type.
     * 3. PR #994 body-flow inference (literal-value `return`s and arrow
     *    functions), invoked only when both the docblock and reflection
     *    are silent on the return type — anything coarser than `mixed`
     *    beats `mixed` for callers chaining off the closure's result.
     * 4. `Type::getMixed()` as the bottom fallback.
     *
     * Returns `null` only when none of those steps improves on what
     * reflection already exposes — caller keeps its existing reflection-only
     * pseudo-method path instead.
     *
     * @param array{docblock: ?array{params: array<string, Union>, return: ?Union}, node: Node\Expr\Closure|Node\Expr\ArrowFunction}|null $recovered
     */
    private static function buildClosureType(\ReflectionFunctionAbstract $reflection, ?array $recovered): ?TClosure
    {
        $docblock = $recovered === null ? null : $recovered['docblock'];
        $node = $recovered === null ? null : $recovered['node'];
        $nativeReturn = self::reflectionTypeToUnion($reflection->getReturnType());

        // Body-flow inference (PR #994) only runs when both the docblock and
        // the native reflected return are silent. With either present, the
        // existing precedence already wins — body inference would at best
        // duplicate work, at worst contradict the explicit declaration.
        $bodyReturn = null;
        if ($docblock === null && !$nativeReturn instanceof \Psalm\Type\Union && $node !== null) {
            $bodyReturn = self::inferReturnFromBody($node);
        }

        if ($docblock === null && !$bodyReturn instanceof \Psalm\Type\Union) {
            return null;
        }

        $params = [];
        foreach ($reflection->getParameters() as $reflParam) {
            $params[] = self::buildClosureParameter($reflParam, $docblock['params'][$reflParam->getName()] ?? null);
        }

        $returnType = $docblock['return'] ?? $nativeReturn ?? $bodyReturn ?? Type::getMixed();

        return new TClosure(params: $params, return_type: $returnType);
    }

    /**
     * PR #994 — narrow body-flow inference for a closure or arrow function.
     *
     * `ArrowFunction` has a single expression body (`$node->expr`) which is by
     * definition the return value. `Closure` may have any number of `return`
     * statements: we walk the body collecting them, infer each, and union the
     * results. Any unhandled node in the expression position bails the entire
     * inference — we never produce a partial answer, because doing so would
     * silently disagree with the closure's actual semantics.
     *
     * Deliberately stops at nested closures / arrow functions / function
     * declarations / class methods: their `return` statements belong to the
     * nested function, not the outer one.
     */
    private static function inferReturnFromBody(Node\Expr\Closure|Node\Expr\ArrowFunction $node): ?Union
    {
        if ($node instanceof Node\Expr\ArrowFunction) {
            return self::inferExpression($node->expr);
        }

        // Guard against implicit-`null` fall-through. PHP returns `null` from a
        // closure whose control flow reaches the closing brace without hitting
        // an explicit `return`. If we ignored that path we would silently
        // produce a narrower type than runtime — e.g. `static function () {
        // if (cond) return 1; }` would surface as `1` instead of `1|null`.
        // Conservatively require the last top-level statement to be a
        // terminating `return` or `throw`; anything else (including
        // `if/else { return; }` symmetric pairs) bails. PHPStan's body inference
        // takes the same conservative posture here.
        if (!self::bodyAlwaysTerminates($node->stmts)) {
            return null;
        }

        $collector = new BodyReturnCollectorVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($node->stmts);

        $returnExprs = $collector->getReturnExpressions();
        if ($collector->hasBailed() || $returnExprs === []) {
            return null;
        }

        $unions = [];
        foreach ($returnExprs as $expr) {
            $inferred = self::inferExpression($expr);
            if (!$inferred instanceof Union) {
                return null;
            }

            $unions[] = $inferred;
        }

        return Type::combineUnionTypeArray($unions, null);
    }

    /**
     * Conservative reachability check: returns `true` only when control flow
     * provably cannot reach the closing brace of the closure body without
     * hitting a `return` or `throw`. Anything more nuanced (e.g. "every branch
     * of this `if/elseif/else` returns") would need a full CFG pass — and
     * since the PR's inference target is short macro closures (one expression
     * body, one straight return, or a final `return` after early-exit guards),
     * the cheap check is enough. False negatives just bail to `mixed`, which
     * is the existing fallback.
     *
     * PhpParser 5 models `throw new X;` as `Stmt\Expression` wrapping
     * `Expr\Throw_` (PHP 8 promoted `throw` to an expression), so the
     * statement-level check has to peek through the expression wrapper.
     *
     * @param array<array-key, Node\Stmt> $stmts
     * @psalm-mutation-free Reads node properties only, mutates nothing.
     */
    private static function bodyAlwaysTerminates(array $stmts): bool
    {
        if ($stmts === []) {
            return false;
        }

        $last = \end($stmts);
        if ($last instanceof Node\Stmt\Return_) {
            return true;
        }

        return $last instanceof Node\Stmt\Expression && $last->expr instanceof Node\Expr\Throw_;
    }

    /**
     * Infer a {@see Union} for a single expression node, or `null` when the
     * node falls outside the deliberately-narrow set the PR supports.
     *
     * Rule table — see PR #994's prompt for the source spec:
     *
     * | Node                            | Inferred                                  |
     * |---------------------------------|-------------------------------------------|
     * | `Scalar\String_`                | `Type::getString($value)`                 |
     * | `Scalar\Int_`                   | `Type::getInt(false, $value)`             |
     * | `Scalar\Float_`                 | `Type::getFloat($value)`                  |
     * | `Expr\ConstFetch` true/false/null | matching constant Union                 |
     * | `Expr\Array_` (all inferable)   | shaped array via `TKeyedArray::make()`    |
     * | `Expr\BinaryOp\Concat` of literal strings | concatenated literal-string     |
     * | `Expr\BinaryOp` arithmetic of inferable numerics | `int|float`            |
     * | anything else                   | `null` (caller bails)                     |
     */
    private static function inferExpression(Node\Expr $expr): ?Union
    {
        if ($expr instanceof Node\Scalar\String_) {
            return self::literalStringUnion($expr->value);
        }

        if ($expr instanceof Node\Scalar\Int_) {
            return Type::getInt(false, $expr->value);
        }

        if ($expr instanceof Node\Scalar\Float_) {
            return Type::getFloat($expr->value);
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            // Globally-namespaced `true`/`false`/`null`. We do not honour
            // `use const` aliases or user-defined constants — those would
            // require a constant resolver, well outside the PR's scope.
            return match (\strtolower($expr->name->toString())) {
                'true' => Type::getTrue(),
                'false' => Type::getFalse(),
                'null' => Type::getNull(),
                default => null,
            };
        }

        if ($expr instanceof Node\Expr\Array_) {
            return self::inferArray($expr);
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return self::inferConcat($expr);
        }

        if ($expr instanceof Node\Expr\BinaryOp && self::isArithmeticBinaryOp($expr)) {
            return self::inferArithmetic($expr);
        }

        return null;
    }

    /**
     * The six PHP-Parser binary-op nodes that produce a numeric result and
     * share a single dispatch branch in {@see self::inferExpression()}.
     * Comparison and boolean operators (`==`, `&&`, `<=>`, …) are deliberately
     * excluded: they widen to `bool` / `int<-1, 1>` and would need their own
     * rules. Named predicate (vs. a six-way `instanceof` chain) makes the
     * arithmetic-vs-other split obvious to readers.
     *
     * @psalm-pure
     */
    private static function isArithmeticBinaryOp(Node\Expr\BinaryOp $expr): bool
    {
        return (
            $expr instanceof Node\Expr\BinaryOp\Plus
            || $expr instanceof Node\Expr\BinaryOp\Minus
            || $expr instanceof Node\Expr\BinaryOp\Mul
            || $expr instanceof Node\Expr\BinaryOp\Div
            || $expr instanceof Node\Expr\BinaryOp\Mod
            || $expr instanceof Node\Expr\BinaryOp\Pow
        );
    }

    /**
     * Build a {@see Union} for `'a' . 'b'`-style concatenations whose operands
     * both reduce to single string literals. Anything coarser (mixed types,
     * non-literal operand, nested non-string union) bails, because we cannot
     * produce a single literal value to fold the result into.
     */
    private static function inferConcat(Node\Expr\BinaryOp\Concat $expr): ?Union
    {
        $left = self::inferExpression($expr->left);
        $right = self::inferExpression($expr->right);
        if (!$left instanceof Union || !$right instanceof Union) {
            return null;
        }

        if (!$left->isSingleStringLiteral() || !$right->isSingleStringLiteral()) {
            return null;
        }

        return self::literalStringUnion(
            $left->getSingleStringLiteral()->value . $right->getSingleStringLiteral()->value,
        );
    }

    /**
     * Build a single-literal-string {@see Union}, bypassing
     * {@see Type::getString()}: that helper routes through
     * `StringInterpreterEvent` and {@see \Psalm\Internal\Analyzer\ProjectAnalyzer},
     * which is unsafe to touch before Psalm's project analyser is bootstrapped
     * (notably during unit tests). Returns `null` on `max_string_length`
     * overflow or when `Config` itself is missing.
     *
     * Not `@psalm-pure`: {@see TLiteralString::make} reads `Config::getInstance()`
     * to enforce `max_string_length`, which Psalm considers impure.
     */
    private static function literalStringUnion(string $value): ?Union
    {
        try {
            // `from_docblock=false`: the value comes from the closure's actual
            // source AST, not a docblock annotation. Matches Psalm's own
            // convention in `ArrayAnalyzer::analyzeArray()` for source-literal
            // arrays, and keeps `from_docblock` aligned with the other
            // source-derived atomics built in `inferExpression()`
            // (`Type::getInt(false, $value)`, `Type::getFloat($value)`,
            // `Type::getTrue/False/Null()`).
            return new Union([TLiteralString::make($value)]);
        } catch (\InvalidArgumentException) {
            // Value exceeds Psalm's configured `max_string_length` — degrade
            // to "no inference" rather than fall back to a wider type the
            // caller would not expect.
            return null;
        } catch (\UnexpectedValueException) {
            // `Config::getInstance()` not initialised. Same posture as
            // PluginConfig::getCacheDir(): treat as "no inference available".
            return null;
        }
    }

    /**
     * Build an `int|float` union for arithmetic operators on numerics. We do
     * not try to fold to a single literal: `1 + 2` could be told to surface
     * as `int(3)`, but the moment the operand list grows (or a `Float_`
     * appears anywhere) the result type can flip integer↔float in ways that
     * are easier to express as `int|float` than to model exactly.
     *
     * The result is constant — operand inference exists purely as a numeric
     * gate, NOT as input to the type. Do not "simplify" by dropping the
     * `inferExpression()` calls: that gate is what prevents `1 + 'x'` from
     * widening to `int|float` (which would be wrong, since the runtime
     * outcome is a `TypeError`).
     */
    private static function inferArithmetic(Node\Expr\BinaryOp $expr): ?Union
    {
        $left = self::inferExpression($expr->left);
        $right = self::inferExpression($expr->right);
        if (!$left instanceof Union || !$right instanceof Union) {
            return null;
        }

        if (!self::isNumericUnion($left) || !self::isNumericUnion($right)) {
            return null;
        }

        // Build a fresh Union per call rather than memoizing. Psalm 7's Union
        // has public mutable fields (`from_docblock`, `ignore_nullable_issues`,
        // …). The instance flows into every `TClosure->return_type` we hand
        // to `MacroDefinition`; an aliased memo would let a downstream
        // mutation on one macro pollute every other arithmetic macro built
        // this run. Psalm itself uses functional setters in practice, but
        // the savings (one `combineUnionTypes` call per arithmetic body
        // return, typically <10 per run) are not worth the risk.
        return Type::combineUnionTypes(Type::getInt(), Type::getFloat());
    }

    /**
     * `int|float`-only check used by {@see self::inferArithmetic()}. We only
     * accept fully-known numeric unions — partial unions like `int|string`
     * would force us to drop into a wider type that defeats the inference.
     *
     * @psalm-mutation-free
     */
    private static function isNumericUnion(Union $union): bool
    {
        foreach ($union->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof Type\Atomic\TInt && !$atomic instanceof Type\Atomic\TFloat) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build a shaped {@see Union} for `[…]` literals. Every entry must have
     * an inferable value AND (if keyed) a literal int/string key. Unpacks
     * (`...$x`) and unkeyed entries with non-sequential mix of keyed entries
     * are conservative bails: we deliberately don't try to model partial
     * arrays. The empty array is its own degenerate case — Psalm exposes a
     * dedicated empty-array Union for it.
     */
    private static function inferArray(Node\Expr\Array_ $expr): ?Union
    {
        if ($expr->items === []) {
            return Type::getEmptyArray();
        }

        $properties = [];
        $nextAutoIndex = 0;
        $anyExplicitKey = false;
        foreach ($expr->items as $item) {
            // `Array_->items` is `list<ArrayItem|null>`: PHP-Parser preserves
            // `null` for skipped positions inside `list(...)`-style destructuring.
            // That is destructuring metadata, not a value-producing literal, so
            // we bail on it.
            if ($item === null || $item->unpack) {
                return null;
            }

            $valueUnion = self::inferExpression($item->value);
            if (!$valueUnion instanceof Union) {
                return null;
            }

            if ($item->key === null) {
                $properties[$nextAutoIndex] = $valueUnion;
                $nextAutoIndex++;
                continue;
            }

            $anyExplicitKey = true;

            $keyExpr = $item->key;
            if ($keyExpr instanceof Node\Scalar\String_) {
                $properties[$keyExpr->value] = $valueUnion;
                continue;
            }

            if ($keyExpr instanceof Node\Scalar\Int_) {
                $properties[$keyExpr->value] = $valueUnion;
                // Subsequent unkeyed entries continue from one past the
                // largest seen integer key — matches PHP's auto-indexing.
                if ($keyExpr->value >= $nextAutoIndex) {
                    $nextAutoIndex = $keyExpr->value + 1;
                }

                continue;
            }

            return null;
        }

        if ($properties === []) {
            return Type::getEmptyArray();
        }

        $isList = !$anyExplicitKey;

        // `from_docblock=false`: matches Psalm's own source-literal convention
        // (`ArrayAnalyzer::analyzeArray()` calls `TKeyedArray::make` with the
        // default `false`). `from_docblock=true` relaxes comparison strictness
        // at call sites — wrong choice for a value we recovered from PHP source.
        return new Union([new TKeyedArray($properties, null, null, $isList)]);
    }

    /**
     * Build a single {@see FunctionLikeParameter} for a closure parameter.
     *
     * Reflection owns name + by-ref / variadic / optional / nullable flags;
     * the docblock contributes type narrowing when it has a matching `@param`.
     * `signature_type` always reflects the native PHP type (used by Psalm to
     * validate the closure body's argument uses), while `type` carries the
     * docblock-narrowed Union when present.
     */
    private static function buildClosureParameter(
        \ReflectionParameter $reflParam,
        ?Union $docblockType,
    ): FunctionLikeParameter {
        $reflType = $reflParam->getType();
        $signatureType = self::reflectionTypeToUnion($reflType);

        return new FunctionLikeParameter(
            name: $reflParam->getName(),
            by_ref: $reflParam->isPassedByReference(),
            type: $docblockType ?? $signatureType,
            signature_type: $signatureType,
            is_optional: $reflParam->isOptional() || $reflParam->isDefaultValueAvailable(),
            // `allowsNull()` covers both `?string` syntax and `string|null`
            // unions, and is more reliable than asking the parsed Union —
            // Psalm's parseString of `?string` does not always set the
            // nullable flag the way we'd expect.
            is_nullable: $reflType?->allowsNull() ?? false,
            is_variadic: $reflParam->isVariadic(),
        );
    }

    /**
     * Convert PHP's reflected type to a Psalm Union. Closures never bind
     * `self`/`static`/`parent` at the *definition* site (only at the call
     * site, via Macroable's `bindTo`), so the simpler form here doesn't take
     * a host class — `static` survives as the literal token for the caller's
     * `TypeExpander` pass to resolve.
     */
    private static function reflectionTypeToUnion(?\ReflectionType $type): ?Union
    {
        if (!$type instanceof \ReflectionType) {
            return null;
        }

        $typeString = (string) $type;
        if ($typeString === '') {
            return null;
        }

        try {
            return Type::parseString($typeString);
        } catch (TypeParseTreeException) {
            return null;
        } catch (\Error $error) {
            // Only the specific "ProjectAnalyzer not initialised" path is
            // benign. Anything else is a real bug — re-throw.
            if (!\str_contains($error->getMessage(), 'ProjectAnalyzer')) {
                throw $error;
            }

            return null;
        }
    }

    /**
     * Extract `@param` and `@return` Unions from a closure's docblock.
     *
     * Reuses Psalm's own {@see DocComment::parsePreservingLength()} for
     * tokenisation and {@see CommentAnalyzer::splitDocLine()} for value-line
     * splitting — the same machinery the analyser runs for stub files and
     * project source. Types are resolved against the closure's enclosing
     * namespace+use aliases (captured by the visitor), so
     * `Collection<int, string>` in vendor code resolves to the
     * fully-qualified `Illuminate\Support\Collection<int, string>` exactly as
     * Psalm would resolve it during a normal scan.
     *
     * Returns `null` when the docblock yields no usable `@param`/`@return`
     * narrowing — empty result means "fall back to reflection".
     *
     * @return array{params: array<string, Union>, return: ?Union}|null
     */
    private static function extractDocblock(Doc $doc, Aliases $aliases): ?array
    {
        // Wraps the whole tag-extraction pass because every helper that
        // touches `CommentAnalyzer::splitDocLine()` can raise
        // `DocblockParseException` (and its subclass
        // `IncorrectDocblockException`) on malformed input — unbalanced
        // brackets, missing newline after a `//` comment inside the docblock,
        // misplaced `$var` token. A vendor file with one bad `@param` would
        // otherwise propagate out of `MacroRegistry::buildDefinition()` and
        // silently break unrelated macros for the rest of the analysis. We
        // degrade to reflection instead — same posture Psalm's own
        // `FunctionLikeDocblockParser` takes when fed a bad docblock.
        //
        // `$no_psalm_error = true` keeps the docblock validator quiet — we
        // are a side-channel extractor, not the primary scanner.
        try {
            $parsed = DocComment::parsePreservingLength($doc, true);

            $paramTypes = [];
            if (isset($parsed->combined_tags['param'])) {
                foreach ($parsed->combined_tags['param'] as $line) {
                    $entry = self::extractParamFromLine($line, $aliases);
                    if ($entry === null) {
                        continue;
                    }

                    [$paramName, $union] = $entry;
                    $paramTypes[$paramName] = $union;
                }
            }

            $returnType = null;
            if (isset($parsed->combined_tags['return'])) {
                foreach ($parsed->combined_tags['return'] as $line) {
                    $returnType = self::extractReturnFromLine($line, $aliases);
                    if ($returnType instanceof Union) {
                        // Take the first usable `@return` — multiple `@return`
                        // tags would be a docblock error, but we'd rather take
                        // the first valid one than silently keep the last.
                        break;
                    }
                }
            }
        } catch (DocblockParseException) {
            return null;
        }

        if ($paramTypes === [] && !$returnType instanceof Union) {
            return null;
        }

        return ['params' => $paramTypes, 'return' => $returnType];
    }

    /**
     * Parse a single `@param <type> $name` docblock value line.
     *
     * Returns `[paramName, Union]` (paramName has the leading `$` stripped to
     * match `\ReflectionParameter::getName()`), or `null` when the line is
     * malformed, has no `$name`, or the type fails to parse.
     *
     * @return array{0: string, 1: Union}|null
     */
    private static function extractParamFromLine(string $line, Aliases $aliases): ?array
    {
        $parts = CommentAnalyzer::splitDocLine($line);
        if (\count($parts) < 2) {
            return null;
        }

        $typeString = CommentAnalyzer::sanitizeDocblockType($parts[0]);
        if ($typeString === '' || $typeString[0] === '$') {
            // First token is a variable name, not a type (`@param $x ...`) —
            // no narrowing to lift.
            return null;
        }

        // Accept optional `&` (by-ref) and `...` (variadic) prefixes and a
        // trailing comma. Reflection already knows by-ref/variadic, so we
        // only capture the bare identifier for the map key.
        if (!\preg_match('/^&?(?:\.\.\.)?\$([A-Za-z_]\w*),?$/', $parts[1], $matches)) {
            return null;
        }

        $union = self::parseDocblockTypeString($typeString, $aliases);
        if (!$union instanceof Union) {
            return null;
        }

        return [$matches[1], $union];
    }

    /**
     * Parse a single `@return <type> [description]` docblock value line into
     * a {@see Union}, or `null` when malformed / unparseable.
     */
    private static function extractReturnFromLine(string $line, Aliases $aliases): ?Union
    {
        // `splitDocLine()` is typed `non-empty-list<string>` so there is
        // always at least one part; the first part holds the type (or a
        // description in the degenerate case where the docblock writer left
        // the type off).
        $parts = CommentAnalyzer::splitDocLine($line);

        $typeString = CommentAnalyzer::sanitizeDocblockType($parts[0]);
        if ($typeString === '') {
            return null;
        }

        return self::parseDocblockTypeString($typeString, $aliases);
    }

    /**
     * Tokenise a docblock type string against the closure's enclosing aliases
     * and parse to a {@see Union}. Failure-mode shape matches
     * {@see self::reflectionTypeToUnion()}: degrade quietly for unparseable
     * input or the test-only `ProjectAnalyzer not initialised` case; re-throw
     * any other engine error so genuine Psalm bugs surface instead of being
     * masked.
     */
    private static function parseDocblockTypeString(string $typeString, Aliases $aliases): ?Union
    {
        try {
            $tokens = TypeTokenizer::getFullyQualifiedTokens($typeString, $aliases);

            return TypeParser::parseTokens($tokens, null, [], [], true);
        } catch (TypeParseTreeException) {
            return null;
        } catch (\Error $error) {
            if (!\str_contains($error->getMessage(), 'ProjectAnalyzer')) {
                throw $error;
            }

            return null;
        }
    }
}
