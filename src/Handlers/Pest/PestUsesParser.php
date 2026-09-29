<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Pest;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Statically reads Pest's `uses(...)` / `pest()->extend(...)` chains from one file, mirroring what
 * Pest itself does at runtime (never executing the file):
 *
 * - `pest()` inside `Pest.php` is rooted at that file's directory (`Pest\Configuration::__construct()`),
 *   so `pest()->extend(X)` alone covers the whole test directory; everywhere else a root without
 *   `in()` targets the declaring file only (`Pest\PendingCalls\UsesCall::__construct()`).
 * - `in()` targets are relative to that root (a file's directory), then glob-expanded and realpath'd
 *   (`UsesCall::in()`).
 *
 * The answer is all-or-nothing: any `uses()` / `pest()` call this cannot read (a non-literal
 * argument, a call nested outside a top-level statement, a first-class callable, an aliased
 * import, the name as a string), and any file whose top-level run is not linear (an include, a
 * top-level `return` / `exit`) makes the file unreadable (`null`), because a guessed TestCase is
 * worse than Pest's own `TestCall` binding.
 *
 * @psalm-type PestUsesEntry = array{classes: list<string>, targets: list<string>}
 */
final class PestUsesParser
{
    private const CLASS_METHODS = ['extend' => true, 'extends' => true, 'use' => true, 'uses' => true];

    /**
     * @param bool $bootFile false for a test file: its closures run after Pest resolved the file's
     *                       TestCase, so an include inside one cannot configure it
     * @return list<PestUsesEntry>|null null when the file holds a chain that cannot be read statically
     */
    public static function parse(string $filePath, string $contents, bool $bootFile = true): ?array
    {
        // Cheap bail: most test files never mention either name, nor include another file. Any
        // mention at all (a comment before the parenthesis, an alias import) takes the AST path.
        if (\preg_match('/\b(?:uses|pest|include|require|include_once|require_once)\b/i', $contents) !== 1) {
            return [];
        }

        try {
            $statements = (new ParserFactory())->createForHostVersion()->parse($contents) ?? [];
        } catch (\PhpParser\Error) {
            return null;
        }

        $traverser = new NodeTraverser(new NameResolver());
        $statements = $traverser->traverse($statements);

        if (!self::isLinear($statements, $bootFile)) {
            return null;
        }

        $entries = [];
        /** @var array<int, true> $readRoots */
        $readRoots = [];

        foreach (self::topLevelExpressions($statements) as $expression) {
            $chain = [];
            while ($expression instanceof MethodCall) {
                if (!$expression->name instanceof Identifier || $expression->isFirstClassCallable()) {
                    return null;
                }

                $chain[] = [\strtolower($expression->name->name), $expression->getArgs()];
                $expression = $expression->var;
            }

            if (!self::isRootCall($expression)) {
                continue;
            }

            if ($expression->isFirstClassCallable()) {
                return null;
            }

            $readRoots[\spl_object_id($expression)] = true;
            $entry = self::readChain($filePath, $expression, \array_reverse($chain));
            if ($entry === null) {
                return null;
            }

            $entries[] = $entry;
        }

        // A root that is not the head of a top-level statement (inside a closure, a condition, an
        // assignment) may or may not run: the file cannot be read reliably.
        foreach ((new NodeFinder())->find($statements, self::isRootCall(...)) as $root) {
            if (!isset($readRoots[\spl_object_id($root)])) {
                return null;
            }
        }

        return $entries;
    }

    /**
     * False when Pest could run config this parser does not see (an include, a call through an
     * aliased import or a string name) or skip config it does see (top-level `return` / `exit`).
     *
     * @param array<Node> $statements
     */
    private static function isLinear(array $statements, bool $bootFile): bool
    {
        $finder = new NodeFinder();
        $opaque = $finder->findFirst($statements, static fn(Node $node): bool => $node instanceof String_
            && \in_array(\strtolower(\ltrim($node->value, '\\')), ['uses', 'pest'], true));
        if ($opaque instanceof \PhpParser\Node) {
            return false;
        }

        $deferredIncludes = [];
        if (!$bootFile) {
            foreach ($finder->find($statements, static fn(Node $node): bool => $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) as $closure) {
                foreach ($finder->findInstanceOf($closure, Expr\Include_::class) as $include) {
                    $deferredIncludes[\spl_object_id($include)] = true;
                }
            }
        }

        foreach ($finder->findInstanceOf($statements, Expr\Include_::class) as $include) {
            if (!isset($deferredIncludes[\spl_object_id($include)])) {
                return false;
            }
        }

        foreach ($finder->find($statements, static fn(Node $node): bool => $node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) as $use) {
            \assert($use instanceof Stmt\Use_ || $use instanceof Stmt\GroupUse);
            foreach ($use->uses as $item) {
                // A plain `use` carries the kind on the statement, a group `use` may carry it per item.
                $name = $use instanceof Stmt\GroupUse ? Name::concat($use->prefix, $item->name) : $item->name;
                if (($item->type !== Stmt\Use_::TYPE_UNKNOWN ? $item->type : $use->type) === Stmt\Use_::TYPE_FUNCTION
                    && $name instanceof Name
                    && \in_array($name->toLowerString(), ['uses', 'pest'], true)
                ) {
                    return false;
                }
            }
        }

        $visitor = new class extends \PhpParser\NodeVisitorAbstract {
            public bool $terminates = false;

            #[\Override]
            public function enterNode(Node $node): ?int
            {
                // Bodies of functions and classes do not run while the file loads.
                if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
                    return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Stmt\Return_ || $node instanceof Expr\Exit_
                    || $node instanceof Stmt\HaltCompiler || $node instanceof Stmt\Goto_
                ) {
                    $this->terminates = true;
                }

                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($statements);

        return !$visitor->terminates;
    }

    /** @psalm-assert-if-true FuncCall $node */
    private static function isRootCall(Node $node): bool
    {
        return $node instanceof FuncCall
            && $node->name instanceof Name
            && \in_array($node->name->toLowerString(), ['uses', 'pest'], true);
    }

    /**
     * @param array<Node> $statements
     * @return \Generator<int, Expr>
     */
    private static function topLevelExpressions(array $statements): \Generator
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                yield from self::topLevelExpressions($statement->stmts);
            } elseif ($statement instanceof Stmt\Expression) {
                yield $statement->expr;
            }
        }
    }

    /**
     * @param list<array{string, array<Node\Arg>}> $chain method calls in call order
     * @return PestUsesEntry|null
     */
    private static function readChain(string $filePath, FuncCall $root, array $chain): ?array
    {
        $isUses = $root->name instanceof Name && $root->name->toLowerString() === 'uses';
        $origin = !$isUses && \basename($filePath) === 'Pest.php' ? \dirname($filePath) : $filePath;

        $classes = $isUses ? self::readStrings($root->getArgs(), $filePath, true) : [];
        $targets = null;

        foreach ($chain as [$method, $args]) {
            if (isset(self::CLASS_METHODS[$method])) {
                $read = self::readStrings($args, $filePath, true);
                $classes = $classes === null || $read === null ? null : [...$classes, ...$read];
            } elseif ($method === 'in') {
                $read = self::readStrings($args, $filePath, false);
                if ($read === null) {
                    return null;
                }

                $targets = self::expandTargets($read, \is_dir($origin) ? $origin : \dirname($origin));
            }
        }

        if ($classes === null) {
            return null;
        }

        return ['classes' => $classes, 'targets' => $targets ?? [self::realpath($origin)]];
    }

    /**
     * @param array<Node\Arg> $args
     * @return list<string>|null
     */
    private static function readStrings(array $args, string $filePath, bool $classNames): ?array
    {
        $values = [];
        foreach ($args as $arg) {
            if ($arg->unpack || $arg->name instanceof Identifier) {
                return null;
            }

            $value = $classNames ? self::readClassName($arg->value) : self::readPath($arg->value, $filePath);
            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values;
    }

    private static function readClassName(Expr $expr): ?string
    {
        if ($expr instanceof String_) {
            return \ltrim($expr->value, '\\');
        }

        if ($expr instanceof Expr\ClassConstFetch
            && $expr->class instanceof Name\FullyQualified
            && $expr->name instanceof Identifier
            && \strtolower($expr->name->name) === 'class'
        ) {
            return $expr->class->toString();
        }

        return null;
    }

    private static function readPath(Expr $expr, string $filePath): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        if ($expr instanceof Dir) {
            return \dirname($filePath);
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = self::readPath($expr->left, $filePath);
            $right = self::readPath($expr->right, $filePath);

            return $left === null || $right === null ? null : $left . $right;
        }

        return null;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private static function expandTargets(array $paths, string $baseDir): array
    {
        $targets = [];
        foreach ($paths as $path) {
            $pattern = \str_starts_with($path, \DIRECTORY_SEPARATOR) ? $path : $baseDir . \DIRECTORY_SEPARATOR . $path;
            $matches = \glob($pattern);
            foreach ($matches === false ? [] : $matches as $match) {
                $targets[] = self::realpath($match);
            }
        }

        return $targets;
    }

    public static function realpath(string $path): string
    {
        $real = \realpath($path);

        return $real === false ? $path : $real;
    }
}
