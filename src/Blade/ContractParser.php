<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Stillat\BladeParser\Document\Document;
use Stillat\BladeParser\Nodes\AbstractNode;
use Stillat\BladeParser\Nodes\CommentNode;
use Stillat\BladeParser\Nodes\DirectiveNode;
use Stillat\BladeParser\Nodes\LiteralNode;
use Stillat\BladeParser\Nodes\Position;

/**
 * Extracts a {@see TemplateContract} from a Blade template: `@var`
 * declarations, `@props` entries, `@psalm-suppress` targets, and the set of
 * top-level variables the compiled body reads. Read-only; wiring the result
 * into shadow compilation is a separate step.
 *
 * @psalm-api
 */
final class ContractParser
{
    /**
     * The `{{-- @var T $name --}}` spelling, matched against a comment's inner content. Greedy on
     * the type, so the name it binds is the LAST `$name` in the comment — `@var Closure(Foo $f): Bar
     * $callback` declares `$callback`, not `$f`.
     *
     * Public because {@see Annotate\TemplateAnnotator} has to recognise exactly what this recognises:
     * a declaration it reads differently is one it appends a duplicate for, forever.
     *
     * The name is matched as PHP matches an identifier, bytes >= 0x80 included (`$menü` is a legal
     * variable), not as `\w`, which is ASCII-only under this pattern.
     */
    public const VAR_PATTERN = '/^\s*@var\s+(.+)\s+\$(' . self::IDENTIFIER . ')\s*$/';

    /** PHP's own variable-name grammar, as bytes. */
    public const IDENTIFIER = '[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*';

    /** A raw `<?php ... ?>` block, whose docblocks are the other spelling a template declares in. */
    private const RAW_PHP_BLOCK = '/<\?php\b.*?(?:\?>|\z)/s';

    /**
     * The name a `@var` docblock binds inside a raw PHP block. Greedy within the line, to bind the
     * same (last) name {@see self::VAR_PATTERN} does; per line, because one block can hold several
     * docblocks and a pattern greedy across them would see only the last. The name grammar is shared
     * with that pattern, so `$menü` binds whole rather than as its ASCII prefix.
     */
    private const RAW_PHP_VAR = '/@var\s+[^\r\n]*\$(' . self::IDENTIFIER . ')/';

    private const SUPPRESS_PATTERN = '/^\s*@psalm-suppress\s+(.+?)\s*$/';

    private ?Parser $parser = null;

    public function parse(string $source, string $compiled): TemplateContract
    {
        [$vars, $suppressions, $propsUnknown] = $this->parseSource($source);

        return new TemplateContract($vars, $this->readVariables($compiled), $suppressions, $propsUnknown);
    }

    /**
     * {@see self::parseDeclarations()} plus the read set, for the UnusedViewData rule: a key the
     * call site passes that neither `$vars` nor `$readVariables` mentions is passed for nothing.
     *
     * Same side-channel rule as parseDeclarations(): the compiled output is read here, never fed
     * back into it.
     */
    public function parseDataContract(string $source, string $compiled): ViewDataContract
    {
        [$vars, , $propsUnknown] = $this->parseSource($source);
        [$reads, $readsUnknown, $localVariables] = $this->parseReads($compiled);

        // Raw declarations are consumed-only, never contract types: in a template a raw `@var` is as
        // often a local type hint after an assignment as a stated interface, and promoting one into
        // $vars would report MissingViewVariable at every call site that correctly omits it.
        $rawDeclared = \array_values(\array_diff(self::rawDeclaredNames($source), \array_keys($vars)));

        return new ViewDataContract($vars, $propsUnknown, $reads, $readsUnknown, $localVariables, $rawDeclared);
    }

    /**
     * The declaration half of {@see self::parse()}, from the template source alone.
     *
     * A side channel for call-site validation: it must not touch the compiled output, because
     * feeding contract types into shadow compilation would change every shadow's content and
     * fingerprint, which is a separate decision from reading the declarations.
     */
    public function parseDeclarations(string $source): ViewDataContract
    {
        [$vars, , $propsUnknown] = $this->parseSource($source);

        return new ViewDataContract($vars, $propsUnknown);
    }

    /**
     * Names a template declares in the raw `<?php` docblock spelling, which
     * {@see self::parseSource()} does not read: `Document::fromText()` hands a raw PHP block back as
     * one opaque node.
     *
     * Tokenized rather than scanned: one raw PHP block can hold a docblock declaring `$title` AND an
     * `echo $body;` after it, and a text scan binds whichever `$name` comes last — `$body`, which is
     * not declared at all, while the declared `$title` is missed. A `@var` inside a string literal is
     * not a declaration either.
     *
     * Public because {@see Annotate\TemplateAnnotator} must recognise exactly what this recognises:
     * a spelling it reads differently is one it appends a duplicate declaration for, forever.
     *
     * @return list<string>
     */
    public static function rawDeclaredNames(string $source): array
    {
        if (\preg_match_all(self::RAW_PHP_BLOCK, $source, $blocks) < 1) {
            return [];
        }

        $names = [];

        foreach ($blocks[0] as $block) {
            // The block can be syntactically incomplete (an unclosed `<?php` at EOF). Tokenizing does
            // not parse, so that is fine; the @ is for the warning an unterminated string emits.
            foreach (@\token_get_all($block) as $token) {
                if (!\is_array($token) || ($token[0] !== \T_DOC_COMMENT && $token[0] !== \T_COMMENT)) {
                    continue;
                }

                if (\preg_match_all(self::RAW_PHP_VAR, $token[1], $matched) > 0) {
                    foreach ($matched[1] as $name) {
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * @return array{0: array<string, ContractVar>, 1: array<int, list<string>>, 2: bool}
     */
    private function parseSource(string $source): array
    {
        $mbLines = \strlen($source) !== \mb_strlen($source);

        try {
            // Reindex: getNodeArray() only promises AbstractNode[], not a list, and
            // nextStatementLine() below needs genuine int keys to walk forward from one.
            $nodes = \array_values(Document::fromText($source)->getNodeArray());
        } catch (\Throwable) {
            // Parse failure means any @props the template may carry is
            // unknowable; flag it rather than claiming "no props".
            return [[], [], true];
        }

        $vars = [];
        $suppressions = [];
        $propsUnknown = false;

        foreach ($nodes as $index => $node) {
            if ($node instanceof CommentNode) {
                $line = $this->nodeLine($node, $source, $mbLines);

                if (\preg_match(self::VAR_PATTERN, $node->innerContent, $matches) === 1) {
                    // Greedy capture keeps a separator space when several precede the
                    // variable name; the type string must not carry it.
                    $vars[$matches[2]] = new ContractVar($matches[2], \rtrim($matches[1]), $line, false);
                }

                if (\preg_match(self::SUPPRESS_PATTERN, $node->innerContent, $matches) === 1) {
                    $targetLine = $this->nextStatementLine($nodes, $index, $source, $mbLines);

                    $rules = SuppressionInjector::parseRuleList($matches[1]);

                    if ($targetLine !== null && $rules !== []) {
                        $suppressions[$targetLine] = [...$suppressions[$targetLine] ?? [], ...$rules];
                    }
                }

                continue;
            }

            if ($node instanceof DirectiveNode && $node->content === 'props') {
                $props = $this->parseProps($node);

                if ($props === null) {
                    $propsUnknown = true;

                    continue;
                }

                $line = $this->nodeLine($node, $source, $mbLines);

                foreach ($props as $name => $optional) {
                    $vars[$name] = new ContractVar($name, 'mixed', $line, $optional);
                }
            }
        }

        return [$vars, $suppressions, $propsUnknown];
    }

    /**
     * @param list<AbstractNode> $nodes
     *
     * @psalm-mutation-free
     */
    private function nextStatementLine(array $nodes, int $afterIndex, string $source, bool $mbLines): ?int
    {
        $counter = \count($nodes);
        for ($i = $afterIndex + 1; $i < $counter; $i++) {
            $candidate = $nodes[$i];

            if ($candidate instanceof CommentNode || $candidate instanceof LiteralNode) {
                continue;
            }

            return $this->nodeLine($candidate, $source, $mbLines);
        }

        return null;
    }

    /**
     * @psalm-mutation-free
     */
    private function nodeLine(AbstractNode $node, string $source, bool $mbLines): int
    {
        $position = $node->position;

        if (!$position instanceof Position || $position->startLine === null) {
            return 1;
        }

        if (!$mbLines) {
            return $position->startLine;
        }

        // blade-parser's own line tracking drifts low once the template contains
        // multi-byte characters; its offsets stay mb-char-accurate, so recompute
        // the line ourselves from the mb-aware prefix instead of trusting startLine.
        return 1 + \substr_count(\mb_substr($source, 0, $position->startOffset), "\n");
    }

    /** One parser for every template in the pass: constructing one re-reads PHP's own token tables. */
    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return array<string, bool>|null prop name => has a literal default; null when the array isn't fully literal
     */
    private function parseProps(DirectiveNode $node): ?array
    {
        $innerContent = $node->arguments?->innerContent;

        if ($innerContent === null || $innerContent === '') {
            return null;
        }

        $parser = $this->parser();

        try {
            $ast = $parser->parse("<?php {$innerContent};");
        } catch (\Throwable) {
            return null;
        }

        $expression = $ast[0] ?? null;

        if (!$expression instanceof Node\Stmt\Expression || !$expression->expr instanceof Node\Expr\Array_) {
            return null;
        }

        $props = [];

        foreach ($expression->expr->items as $item) {
            if ($item === null) {
                return null;
            }

            if ($item->key !== null) {
                if (!$item->key instanceof Node\Scalar\String_) {
                    return null;
                }

                $props[$item->key->value] = true; // carries a default value

                continue;
            }

            if (!$item->value instanceof Node\Scalar\String_) {
                return null;
            }

            $props[$item->value->value] = false; // bare entry, no default
        }

        return $props;
    }

    /**
     * Locally bound names are excluded template-wide, not per scope: an outer
     * read of a name that a later loop or closure re-binds is dropped from the
     * read set. The cost is one missing `mixed` declaration for a shadowed
     * name; scope-aware tracking is not worth it for v1.
     *
     * @return list<string> variable names (without $)
     */
    private function readVariables(string $compiled): array
    {
        [$names, $locals] = $this->walkReads($compiled);

        return $this->filterNames($names, $locals);
    }

    /**
     * The read set the UnusedViewData rule consumes. Two differences from {@see self::readVariables()}:
     * locally bound names stay in (the question is "does the template use this name at all", and
     * dropping them would report the very name a `@foreach` binds), and an unknowable body is
     * flagged rather than reported as an empty set.
     *
     * @return array{0: list<string>, 1: bool, 2: list<string>} names read, whether the set is only a
     *         lower bound, and the names the template binds for itself
     */
    private function parseReads(string $compiled): array
    {
        [$names, $locals, $unknown] = $this->walkReads($compiled);

        return [$this->filterNames($names, []), $unknown, $this->filterNames($locals, [])];
    }

    /**
     * @param array<string, true> $names
     * @param array<string, true> $excluded
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    private function filterNames(array $names, array $excluded): array
    {
        $filtered = [];

        foreach (\array_keys($names) as $name) {
            if ($name === 'this'
                || \str_starts_with($name, '__')
                || isset(PreludeBuilder::BLADE_OWNED_NAMES[$name])
                || isset($excluded[$name])
            ) {
                continue;
            }

            $filtered[] = $name;
        }

        \sort($filtered);

        return $filtered;
    }

    /**
     * One walk of the compiled body, feeding both read-set consumers.
     *
     * @return array{0: array<string, true>, 1: array<string, true>, 2: bool} names read, the names
     *         the body binds for itself, and whether it hides which names it reads
     */
    private function walkReads(string $compiled): array
    {
        $parser = $this->parser();

        try {
            $ast = $parser->parse($compiled) ?? [];
        } catch (\Throwable) {
            // Unparseable, not empty: an empty read set reads as "the template reads nothing", which
            // would turn every key a call site passes into a false positive.
            return [[], [], true];
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $names = [];

            /** @var array<string, true> */
            public array $locals = [];

            public bool $unknown = false;

            #[\Override]
            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef) {
                    if ($node->var instanceof Node\Expr\Variable) {
                        $node->var->setAttribute('contractSkip', true);
                    }

                    $this->bindLocals($node->var);
                }

                if ($node instanceof Node\Stmt\Foreach_) {
                    $this->bindLocals($node->valueVar);
                    $this->bindLocals($node->keyVar);
                }

                // A closure or arrow-function parameter, a `catch` variable, and a `static`/`global`
                // declaration all bind a name the template supplies itself, exactly as a loop alias
                // does. A `use ($x)` clause is deliberately absent: it READS the enclosing $x.
                if ($node instanceof Node\Param || $node instanceof Node\StaticVar) {
                    $this->bindLocals($node->var);
                }

                if ($node instanceof Node\Stmt\Catch_) {
                    $this->bindLocals($node->var);
                }

                if ($node instanceof Node\Stmt\Global_) {
                    foreach ($node->vars as $global) {
                        $this->bindLocals($global);
                    }
                }

                if ($node instanceof Node\Expr\Variable) {
                    if (!\is_string($node->name)) {
                        // `$$name`: what Blade's own `@props` / `@aware` output compiles to, and the
                        // one shape that reads (or injects) a name no static walk can enumerate.
                        $this->unknown = true;
                    } elseif ($node->getAttribute('contractSkip') !== true) {
                        $this->names[$node->name] = true;
                    }
                }

                if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                    $this->enterCall(\strtolower($node->name->toString()), $node->args);
                }

                return null;
            }

            /**
             * Every name one binding construct introduces, list destructuring included: `as [$id,
             * $name]` binds both, and a call site is expected to pass neither.
             */
            private function bindLocals(?Node\Expr $target): void
            {
                if ($target instanceof Node\Expr\Variable && \is_string($target->name)) {
                    $this->locals[$target->name] = true;

                    return;
                }

                if (!$target instanceof Node\Expr\Array_ && !$target instanceof Node\Expr\List_) {
                    return;
                }

                foreach ($target->items as $item) {
                    // A skipped slot (`[, $b]`) is null; the key of a keyed destructure is read, not
                    // bound, so only the value side recurses.
                    if ($item instanceof Node\ArrayItem) {
                        $this->bindLocals($item->value);
                    }
                }
            }

            /**
             * @param array<array-key, Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args
             *
             * @psalm-external-mutation-free
             */
            private function enterCall(string $name, array $args): void
            {
                if ($name === 'extract') {
                    $this->unknown = true;

                    return;
                }

                // `get_defined_vars()` is deliberately not in this list: Blade compiles it into every
                // `@include`, so reading it as unknown would turn the rule off almost everywhere.
                if ($name !== 'compact') {
                    return;
                }

                foreach ($args as $arg) {
                    if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
                        $this->names[$arg->value->value] = true;

                        continue;
                    }

                    $this->unknown = true;
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return [$visitor->names, $visitor->locals, $visitor->unknown];
    }
}
