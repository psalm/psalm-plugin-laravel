<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Builds the leading `<?php … ?>` block of standalone one-line docblocks
 * (`/** @var \FQCN $name *\/`) that seeds every shadow file. Psalm reads a
 * standalone `@var` docblock as declaring that variable's type for the rest
 * of the scope, which silences UndefinedGlobalVariable for names Blade
 * injects at render time but the compiled output never assigns.
 *
 * @internal
 */
final class PreludeBuilder
{
    /** @var array<string, string> variable name (without $) => FQCN, present in EVERY compiled view */
    public const AMBIENT_TYPES = [
        '__env' => '\Illuminate\View\Factory',
        'errors' => '\Illuminate\Support\ViewErrorBag',
        // Blade's loop cursor is a plain stdClass built from an array (ManagesLoops::getLastLoop()).
        'loop' => 'object{index: int, iteration: int, remaining: int|null, count: int|null, first: bool, last: bool|null, odd: bool, even: bool, depth: int, parent: object|null}',
    ];

    /**
     * Every name Blade injects into a compiled view itself, declared or not (`$component` is
     * NEVER declared, see {@see componentTypesFor()}). This is the "Blade owns this name"
     * question a read-set filter or UnusedViewData check asks, distinct from AMBIENT_TYPES,
     * which asks "does the prelude always declare a type for this name".
     */
    public const BLADE_OWNED_NAMES = [
        '__env' => true, 'errors' => true, 'loop' => true,
        'attributes' => true, 'slot' => true, 'component' => true,
    ];

    private const COMPONENT_ATTRIBUTES_TYPE = '\Illuminate\View\ComponentAttributeBag';

    private const COMPONENT_SLOT_TYPE = '\Illuminate\View\ComponentSlot';

    /** Type words that resolve the same anywhere in the file, so a `@var` using only these (plus FQCNs) may move. */
    private const LIFTABLE_KEYWORDS = [
        'array' => true, 'array-key' => true, 'bool' => true, 'callable' => true, 'class-string' => true,
        'false' => true, 'float' => true, 'int' => true, 'iterable' => true, 'list' => true, 'max' => true,
        'min' => true, 'mixed' => true, 'negative-int' => true, 'non-empty-array' => true,
        'non-empty-list' => true, 'non-empty-string' => true, 'non-negative-int' => true, 'null' => true,
        'numeric' => true, 'numeric-string' => true, 'object' => true, 'positive-int' => true,
        'scalar' => true, 'string' => true, 'true' => true,
    ];

    private const UNWRITTEN_MARKER = '/* unwritten */';

    private ?Parser $parser = null;

    /**
     * The prelude plus the compiled body it pairs with. A name the template documents in its own
     * raw `@var` and first reads through `isset()`, `??` or `??=` is optional view data (#1697):
     * the prelude declares it with that type inside a `try`, which Psalm models as typed AND
     * possibly undefined, and the body's own tag for it is neutralized so it cannot re-declare the
     * name as always set. An unguarded read then reports PossiblyUndefinedGlobalVariable.
     *
     * @param array<string, string> $contractVars variable name (without $) => FQCN
     *
     * @return array{0: string, 1: string} prelude, compiled body (same bytes and line breaks)
     */
    public function compose(string $compiled, array $contractVars, string $source): array
    {
        $componentTypes = \array_filter(
            self::componentTypesFor($source),
            static fn(?string $type): bool => $type !== null,
        );

        $declared = self::AMBIENT_TYPES + $componentTypes + $contractVars;

        $lines = [];

        foreach (self::AMBIENT_TYPES as $name => $type) {
            $lines[] = "/** @var {$type} \${$name} */";
        }

        foreach ($componentTypes as $name => $type) {
            $lines[] = "/** @var {$type} \${$name} */";
        }

        foreach ($contractVars as $name => $type) {
            $lines[] = "/** @var {$type} \${$name} */";
        }

        [$undeclared, $firstGuarded, $mayWrite] = $this->undeclaredVariables($compiled, $declared);
        $tags = $this->rawVarTags($compiled);
        $lifted = [];

        foreach ($undeclared as $name) {
            $tag = $tags[$name] ?? [];

            if (isset($firstGuarded[$name], $tag[0]) && \count($tag) === 1 && !isset(self::BLADE_OWNED_NAMES[$name])) {
                [$offset, $type] = $tag[0];

                if ($type !== null) {
                    // A name the body never writes is undefined on EVERY path, so each of its guards
                    // is meaningful; the marker lets the relocator drop what Psalm reports on the
                    // second one (see liftsUnwritten()).
                    $lifted[] = "/** @var {$type} \${$name} */ \${$name} = \$GLOBALS['{$name}'];"
                        . (isset($mayWrite[$name]) ? '' : ' ' . self::UNWRITTEN_MARKER);
                    // Same length, still a tag (so the next line is not read as its continuation),
                    // but no longer one Psalm declares a type from.
                    $compiled = \substr_replace($compiled, '@opt', $offset, 4);

                    continue;
                }
            }

            $lines[] = "/** @var mixed \${$name} */";
        }

        if ($lifted !== []) {
            $lines[] = 'try { ' . \implode(' ', $lifted) . ' } catch (\\Throwable) {}';
        }

        return ["<?php\n" . \implode("\n", $lines) . "\n?>\n", $compiled];
    }

    /**
     * Whether a shadow's own prelude declared `$name` as an optional view variable via
     * {@see compose()}, read back from the shadow text so a relocated issue can be told apart
     * from one on an author's own `try`.
     *
     * @psalm-pure
     */
    public static function liftsOptional(string $shadow, string $name): bool
    {
        return self::preludeDeclares($shadow, $name, '');
    }

    /**
     * Whether {@see compose()} also marked that optional `$name` as never written by the body.
     *
     * @psalm-pure
     */
    public static function liftsUnwritten(string $shadow, string $name): bool
    {
        return self::preludeDeclares($shadow, $name, ' ' . \preg_quote(self::UNWRITTEN_MARKER, '/'));
    }

    /**
     * Where the `@opt` tag {@see compose()} left in the body sits, for the optional declaration
     * covering `$offset` in the prelude, so an issue Psalm raises on the lifted TYPE can report
     * where the template wrote it. Null when `$offset` is not in a lifted declaration.
     *
     * @return array{0: int, 1: int}|null shadow offsets of the doc comment's start and of the tag
     *
     * @psalm-pure
     */
    public static function optionalTagOffset(string $shadow, int $offset): ?array
    {
        $end = \strpos($shadow, "\n?>\n");

        if ($end === false || $offset >= $end
            || \preg_match_all('/\/\*\* @var .+? \$(' . ContractParser::IDENTIFIER . ') \*\/ \$\1 = \$GLOBALS\[[^\]]+\];/', \substr($shadow, 0, $end), $declarations, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) < 1
        ) {
            return null;
        }

        foreach ($declarations as $declaration) {
            [$text, $start] = $declaration[0];

            if ($offset >= $start && $offset < $start + \strlen($text)) {
                $name = $declaration[1][0];

                foreach (self::docCommentTags($shadow, '@opt') as [$tagOffset, $rest, , $commentStart]) {
                    if ($tagOffset > $end && \preg_match('/^\s.*?\$' . \preg_quote($name, '/') . '(?![\w\x80-\xff])/', $rest) === 1) {
                        return [$commentStart, $tagOffset];
                    }
                }
            }
        }

        return null;
    }

    /** @psalm-pure */
    private static function preludeDeclares(string $shadow, string $name, string $suffix): bool
    {
        $end = \strpos($shadow, "\n?>\n");
        $quoted = \preg_quote($name, '/');

        return $end !== false
            && \preg_match("/^try \\{ .* \\\${$quoted} = \\\$GLOBALS\\['{$quoted}'\\];{$suffix}/m", \substr($shadow, 0, $end)) === 1;
    }

    /**
     * Every `$tag` occurrence in a real doc comment (tokenizer-verified, so author text in a
     * string, inline HTML or a plain comment never counts), with the rest of its line.
     *
     * @return list<array{0: int, 1: string, 2: string, 3: int}> offset of the tag, rest of its line,
     *     the tag itself, offset of the doc comment
     *
     * @psalm-pure
     */
    private static function docCommentTags(string $source, string $tag): array
    {
        $tags = [];
        $offset = 0;

        foreach (@\token_get_all($source) as $token) {
            $text = \is_array($token) ? $token[1] : $token;

            if (\is_array($token) && $token[0] === \T_DOC_COMMENT
                && \preg_match_all('/' . $tag . '\b([^\n]*)/', $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) > 0
            ) {
                foreach ($matches as $match) {
                    $tags[] = [$offset + $match[0][1], $match[1][0], \substr($match[0][0], 0, \strlen($match[0][0]) - \strlen($match[1][0])), $offset];
                }
            }

            $offset += \strlen($text);
        }

        return $tags;
    }

    /**
     * Every `@var`-family tag in the compiled body's real doc comments (tokenizer-verified, so
     * author text in a string, inline HTML or a plain comment never counts), by declared name.
     * The type is null unless the tag is a plain `@var` whose type is safe to move into the
     * prelude: a relative class name resolves against the template's own `use` imports, which the
     * prelude sits above.
     *
     * @return array<string, list<array{0: int, 1: ?string}>> name => [offset of `@var`, liftable type]
     *
     * @psalm-mutation-free
     */
    private function rawVarTags(string $compiled): array
    {
        $tags = [];

        foreach (self::docCommentTags($compiled, '@(?:psalm-|phpstan-)?var') as [$offset, $rest, $tag]) {
            // Only the declared name counts: `$f` in "Shown next to $f" is description text.
            if (\preg_match('/^\s+(?:([^$]+?)\s+)?&?(?:\.\.\.)?\$(' . ContractParser::IDENTIFIER . ')(?![\w\x80-\xff])/', $rest, $typed) === 1) {
                $type = $tag === '@var' && $typed[1] !== '' && $this->isLiftableType($typed[1]) ? $typed[1] : null;
                $tags[$typed[2]][] = [$offset, $type];

                continue;
            }

            // Unparseable: attribute it to every name it mentions, so none is lifted past it.
            \preg_match_all('/\$(' . ContractParser::IDENTIFIER . ')/', $rest, $names);

            foreach (\array_unique($names[1]) as $name) {
                $tags[$name][] = [$offset, null];
            }
        }

        return $tags;
    }

    /** @psalm-pure */
    private function isLiftableType(string $type): bool
    {
        \preg_match_all('/\\\\?[A-Za-z_][\w\\\\-]*/', $type, $words);

        foreach ($words[0] as $word) {
            if ($word[0] !== '\\' && !isset(self::LIFTABLE_KEYWORDS[\strtolower($word)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * `$attributes` and `$slot`, typed from the template's own SOURCE alone (never its compiled
     * output, never a cross-template pass): a caller of `<x-foo/>` never writes either name
     * itself, so any match means this template IS a component view (reachable as `<x-*>`,
     * `@component`, or both — {@see \Illuminate\View\Concerns\ManagesComponents::componentData()}
     * gives both render paths the same ComponentSlot for `$slot`; only `$attributes` is
     * render-path-sensitive, and that distinction cannot be told apart statically here).
     *
     * `@props([...])` runs `$attributes ??= new ComponentAttributeBag(...)`
     * (`Concerns/CompilesComponents.php::compileProps()`), so only that branch may see the
     * attributes bag as genuinely absent and gets the nullable type; `@aware([...])` emits no such
     * assignment, and a bare `$attributes` mention with neither directive means Laravel's own
     * `Component::data()` / `AnonymousComponent::data()` already guarantees the key, so both get
     * the non-null type.
     *
     * A live `@props`/`@aware` directive also declares `$slot` whether or not the template ever
     * names it: both render paths always supply one, so an indirect read must not fall through to
     * UndefinedGlobalVariable. A bare `$attributes` mention does NOT — it is a guess over a name
     * the template merely happens to use, too weak to declare a second name never written.
     *
     * @return array{attributes: ?string, slot: ?string}
     *
     * @psalm-pure
     */
    public static function componentTypesFor(string $source): array
    {
        // Comments and verbatim bodies never compile, so a directive or mention inside one is not
        // evidence of anything: a commented `@props` emits no `??=` guard (typing `$attributes`
        // nullable off it invents a PossiblyNullReference on a valid component), and a commented
        // mention would classify a plain page as a component view.
        $source = MarkerPrePass::blankInertText($source);

        $attributes = null;
        $directive = false;

        // Case-insensitive: Blade dispatches a directive via `method_exists($this,
        // 'compile'.ucfirst($name))`, which PHP resolves case-insensitively, so `@PROPS(...)`
        // compiles identically to `@props(...)`. `(?<!@)`: `@@props(...)` is an ESCAPED directive
        // that compiles to the literal text `@props(...)`, never to a call. `\b`-anchored:
        // `str_contains()` would also match `$attributesFoo`/`$slots_count`, a longer identifier
        // rather than a mention of the ambient name itself, which would misclassify a plain page as
        // a component view and widen the relocator's drop gate on it (#1525 review).
        if (\preg_match('/(?<!@)@props\s*\(/i', $source) === 1) {
            $attributes = '?' . self::COMPONENT_ATTRIBUTES_TYPE;
            $directive = true;
        } elseif (\preg_match('/(?<!@)@aware\s*\(/i', $source) === 1) {
            $attributes = self::COMPONENT_ATTRIBUTES_TYPE;
            $directive = true;
        } elseif (\preg_match('/\$attributes\b/', $source) === 1) {
            $attributes = self::COMPONENT_ATTRIBUTES_TYPE;
        }

        $slot = $directive || \preg_match('/\$slot\b/', $source) === 1
            ? self::COMPONENT_SLOT_TYPE
            : null;

        return ['attributes' => $attributes, 'slot' => $slot];
    }

    /**
     * Whether {@see componentTypesFor()} declares anything at all for this template's source.
     *
     * @psalm-pure
     */
    public static function isComponentView(string $source): bool
    {
        return self::componentTypesFor($source) !== ['attributes' => null, 'slot' => null];
    }

    /**
     * Every class name a shadow's prelude can ever emit a docblock for, for a caller that must
     * queue them all for scanning (BladeBootstrapper) rather than read their docblock type. Both
     * AMBIENT_TYPES (always declared) and the component-only names (declared in SOME shadows only)
     * belong here: Psalm's scanner never reads a class name out of a stacked prelude docblock, so
     * a name absent from this list reports UndefinedDocblockClass the moment any shadow declares
     * it. Excludes `loop`, whose value is an inline object shape, not a class name.
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    public static function ambientClassNames(): array
    {
        $types = self::AMBIENT_TYPES + [
            'attributes' => self::COMPONENT_ATTRIBUTES_TYPE,
            'slot' => self::COMPONENT_SLOT_TYPE,
        ];

        return \array_values(\array_filter(
            $types,
            static fn(string $type): bool => $type[0] === '\\',
        ));
    }

    /** One parser for every template in the pass: constructing one re-reads PHP's own token tables. */
    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param array<string, string> $declared
     * @return array{0: list<string>, 1: array<string, true>, 2: array<string, true>} variable names
     *     (without $), sorted and deduplicated; the names whose first read in AST order is a guard at
     *     file scope; the names the body may write in any way
     */
    private function undeclaredVariables(string $compiled, array $declared): array
    {
        $parser = $this->parser();

        // A collecting handler mirrors Psalm's own error-tolerant parse (StatementsProvider uses
        // the same class): a syntax error anywhere in the compiled shadow must drop only the
        // erroring statement, not the whole net — Psalm still analyzes every statement it recovers
        // and would otherwise report UndefinedGlobalVariable for names this pass never sees.
        // A null result (unrecoverable, e.g. brace imbalance) or a throw still drops the net,
        // which is safe: Psalm's parse of that shadow yields no statements either, so nothing
        // is analyzed and only ParseError is reported.
        try {
            $ast = $parser->parse($compiled, new Collecting()) ?? [];
        } catch (\Throwable) {
            return [[], [], []];
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $found = [];

            /** @var array<string, true> */
            public array $written = [];

            /** @var array<string, true> */
            public array $firstGuarded = [];

            /** @var array<string, true> the written names plus every write this pass cannot rule out */
            public array $mayWrite = [];

            /** A construct that can define any name (`$$x`, `extract()`, `include`, ...) occurs. */
            public bool $dynamicWrite = false;

            /** @var array<int, true> object ids of the variables a file-scope guard reads */
            private array $guards = [];

            private int $functionDepth = 0;

            #[\Override]
            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\FunctionLike) {
                    ++$this->functionDepth;
                } elseif ($this->functionDepth === 0) {
                    $this->collectGuards($node);
                }

                // Parents enter before children, so the first visit of a name is its first use.
                $this->collectPossibleWrites($node);

                if ($node instanceof Node\Expr\Variable && \is_string($node->name)) {
                    if (!isset($this->found[$node->name]) && isset($this->guards[\spl_object_id($node)])) {
                        $this->firstGuarded[$node->name] = true;
                    }

                    $this->found[$node->name] = true;
                }

                if ($node instanceof Node\Expr\Assign
                    || $node instanceof Node\Expr\AssignOp
                    || $node instanceof Node\Expr\AssignRef
                ) {
                    $this->markWritten($node->var);
                }

                if ($node instanceof Node\Stmt\Foreach_) {
                    $this->markWritten($node->valueVar);

                    if ($node->keyVar instanceof \PhpParser\Node\Expr) {
                        $this->markWritten($node->keyVar);
                    }
                }

                return null;
            }

            /**
             * @psalm-external-mutation-free
             */
            #[\Override]
            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\FunctionLike) {
                    --$this->functionDepth;
                }

                return null;
            }

            /**
             * Writes {@see markWritten()} does not count because they are not plain assignments:
             * by-reference bindings, `unset`, `global`/`static`, and any call argument that may be
             * taken by reference. Over-approximates; only the never-written marker relies on it.
             */
            private function collectPossibleWrites(Node $node): void
            {
                if (($node instanceof Node\Expr\Variable && !\is_string($node->name))
                    || $node instanceof Node\Expr\Include_
                    || $node instanceof Node\Expr\Eval_
                    || ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                        && \in_array($node->name->toLowerString(), ['extract', 'parse_str', 'get_defined_vars'], true))
                ) {
                    $this->dynamicWrite = true;
                }

                $targets = match (true) {
                    $node instanceof Node\Stmt\Unset_ => $node->vars,
                    $node instanceof Node\Stmt\Global_ => $node->vars,
                    $node instanceof Node\Stmt\Static_ => \array_map(static fn(Node\StaticVar $var): Node\Expr\Variable => $var->var, $node->vars),
                    $node instanceof Node\ClosureUse && $node->byRef => [$node->var],
                    $node instanceof Node\Expr\AssignRef => [$node->expr],
                    $node instanceof Node\Expr\CallLike && !$node->isFirstClassCallable() => $this->byRefCandidates($node),
                    default => [],
                };

                foreach ($targets as $target) {
                    $this->markWritten($target, false);
                }
            }

            /**
             * Arguments of a call that may bind by reference: all of them unless the callee is a
             * plain function this process can reflect.
             *
             * @return list<Node\Expr>
             */
            private function byRefCandidates(Node\Expr\CallLike $call): array
            {
                $parameters = null;

                $function = $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name ? $call->name->toString() : null;

                if ($function !== null && \function_exists($function)) {
                    $parameters = (new \ReflectionFunction($function))->getParameters();
                }

                $candidates = [];

                foreach (\array_values($call->getArgs()) as $index => $arg) {
                    $parameter = $parameters === null || $arg->name !== null || $arg->unpack ? null : ($parameters[$index] ?? \end($parameters));

                    if (!$parameter instanceof \ReflectionParameter || $parameter->isPassedByReference()) {
                        $candidates[] = $arg->value;
                    }
                }

                return $candidates;
            }

            /**
             * @psalm-external-mutation-free
             */
            private function collectGuards(Node $node): void
            {
                $operands = match (true) {
                    $node instanceof Node\Expr\Isset_ => $node->vars,
                    $node instanceof Node\Expr\BinaryOp\Coalesce => [$node->left],
                    $node instanceof Node\Expr\AssignOp\Coalesce => [$node->var],
                    default => [],
                };

                foreach ($operands as $operand) {
                    $this->guards[\spl_object_id($operand)] = true;
                }
            }

            /**
             * Walks a write target down to its root variables (array append, list destructuring).
             *
             * @psalm-external-mutation-free
             */
            private function markWritten(Node\Expr $target, bool $definite = true): void
            {
                if ($target instanceof Node\Expr\Variable && \is_string($target->name)) {
                    if ($definite) {
                        $this->written[$target->name] = true;
                    }

                    $this->mayWrite[$target->name] = true;

                    return;
                }

                if ($target instanceof Node\Expr\ArrayDimFetch) {
                    $this->markWritten($target->var, $definite);

                    return;
                }

                if ($target instanceof Node\Expr\List_) {
                    foreach ($target->items as $item) {
                        if ($item !== null) {
                            $this->markWritten($item->value, $definite);
                        }
                    }
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $names = [];

        foreach (\array_keys($visitor->found) as $name) {
            if (isset($declared[$name])) {
                continue;
            }

            // A `__`-prefixed name the compiled output WRITES is compiler bookkeeping (Blade's
            // `@session`/`@context` append to `$__sessionPrevious`/`$__contextPrevious` without a
            // whole assignment); declaring it `mixed` widens the append result and turns the
            // compiler's own `!empty()` epilogue into a RiskyTruthyFalsyComparison on the template
            // line. Only a read-only `__` name is a host-app shared global (#1558).
            if (\str_starts_with($name, '__') && isset($visitor->written[$name])) {
                continue;
            }

            $names[] = $name;
        }

        \sort($names);

        // A dynamic write (`@props`/`@aware` compile to `$$__key = ...`) can define any name, so
        // no first read is provably a read of an undefined variable.
        return [$names, $visitor->dynamicWrite ? [] : $visitor->firstGuarded, $visitor->mayWrite];
    }
}
