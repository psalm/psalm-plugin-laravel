<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util\Ast;

use PhpParser\Comment\Doc;
use PhpParser\Error as PhpParserError;
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
 * Gap vs PHPStan: body-flow inference. PHPStan's `ClosureTypeFactory` infers
 * the return type from `return` statements when no `@return` docblock is
 * present (e.g. `fn () => 'x'` produces a literal-string return). We
 * currently fall back to the native reflection return type or `mixed`.
 * Tracked as follow-up work — see the "Body-flow inference" plan in PR #994's
 * description.
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
     * - the closure has no docblock-derived narrowing the caller could not
     *   already produce from reflection alone. Caller falls back to a
     *   reflection-only pseudo-method in that case.
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
     * @param callable(string): ?array<int, list<array{0: ?Doc, 1: Aliases}>> $indexer
     */
    public static function buildWithIndexer(\Closure $closure, callable $indexer): ?TClosure
    {
        $reflection = new \ReflectionFunction($closure);

        $docblock = self::recoverDocblock($reflection, $indexer);

        return self::buildClosureType($reflection, $docblock);
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
     * @return array<int, list<array{0: ?Doc, 1: Aliases}>>|null
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
     * Lift `@param` and `@return` Unions from the closure's source-attached
     * docblock, or `null` when no usable docblock is reachable.
     *
     * @param callable(string): ?array<int, list<array{0: ?Doc, 1: Aliases}>> $indexer
     * @return array{params: array<string, Union>, return: ?Union}|null
     */
    private static function recoverDocblock(\ReflectionFunctionAbstract $reflection, callable $indexer): ?array
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

        [$doc, $aliases] = $matches[0];
        if (!$doc instanceof Doc) {
            return null;
        }

        return self::extractDocblock($doc, $aliases);
    }

    /**
     * Build the final {@see TClosure}: reflection-derived parameter list with
     * docblock narrowing per parameter, plus the docblock return type when
     * present, otherwise the native return type or `mixed`.
     *
     * Returns `null` when no docblock narrowing was recovered — the caller
     * would not learn anything beyond what reflection already exposes, so it
     * keeps its existing reflection-only pseudo-method path instead.
     *
     * @param array{params: array<string, Union>, return: ?Union}|null $docblock
     */
    private static function buildClosureType(\ReflectionFunctionAbstract $reflection, ?array $docblock): ?TClosure
    {
        if ($docblock === null) {
            return null;
        }

        $params = [];
        foreach ($reflection->getParameters() as $reflParam) {
            $params[] = self::buildClosureParameter($reflParam, $docblock['params'][$reflParam->getName()] ?? null);
        }

        $nativeReturn = self::reflectionTypeToUnion($reflection->getReturnType());
        // Issue #991: docblock `@return` (when present) wins over the native
        // reflection return — that's the whole point of recovery. Native
        // return is preserved only when the docblock is silent.
        $returnType = $docblock['return'] ?? $nativeReturn ?? Type::getMixed();

        return new TClosure(
            params: $params,
            return_type: $returnType,
        );
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
    private static function buildClosureParameter(\ReflectionParameter $reflParam, ?Union $docblockType): FunctionLikeParameter
    {
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
