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
    private const VAR_PATTERN = '/^\s*@var\s+(.+)\s+\$(\w+)\s*$/';

    private const SUPPRESS_PATTERN = '/^\s*@psalm-suppress\s+(\S+)\s*$/';

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
        [$reads, $readsUnknown] = $this->parseReads($compiled);

        return new ViewDataContract($vars, $propsUnknown, $reads, $readsUnknown);
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

                    if ($targetLine !== null) {
                        $suppressions[$targetLine][] = $matches[1];
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
     * Loop aliases are excluded template-wide, not per scope: an outer read of
     * a name that a later loop re-binds as its alias is dropped from the read
     * set. The cost is one missing `mixed` declaration for a shadowed name;
     * scope-aware tracking is not worth it for v1.
     *
     * @return list<string> variable names (without $)
     */
    private function readVariables(string $compiled): array
    {
        [$names, $loopLocals] = $this->walkReads($compiled);

        return $this->filterNames($names, $loopLocals);
    }

    /**
     * The read set the UnusedViewData rule consumes. Two differences from {@see self::readVariables()}:
     * loop aliases stay in (the question is "does the template use this name at all", and dropping
     * the alias would report the very name a `@foreach` binds), and an unknowable body is flagged
     * rather than reported as an empty set.
     *
     * @return array{0: list<string>, 1: bool} names read, and whether the set is only a lower bound
     */
    private function parseReads(string $compiled): array
    {
        [$names, , $unknown] = $this->walkReads($compiled);

        return [$this->filterNames($names, []), $unknown];
    }

    /**
     * @param array<string, true> $names
     * @param array<string, true> $excluded
     *
     * @return list<string>
     */
    private function filterNames(array $names, array $excluded): array
    {
        $filtered = [];

        foreach (\array_keys($names) as $name) {
            if ($name === 'this'
                || \str_starts_with($name, '__')
                || isset(PreludeBuilder::AMBIENT_TYPES[$name])
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
     * @return array{0: array<string, true>, 1: array<string, true>, 2: bool} names read, the loop
     *         aliases among them, and whether the body hides which names it reads
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
            public array $loopLocals = [];

            public bool $unknown = false;

            #[\Override]
            public function enterNode(Node $node): null
            {
                if (($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef)
                    && $node->var instanceof Node\Expr\Variable
                ) {
                    $node->var->setAttribute('contractSkip', true);
                }

                if ($node instanceof Node\Stmt\Foreach_) {
                    foreach ([$node->valueVar, $node->keyVar] as $loopVar) {
                        if ($loopVar instanceof Node\Expr\Variable && \is_string($loopVar->name)) {
                            $this->loopLocals[$loopVar->name] = true;
                        }
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

            /** @param array<array-key, Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args */
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

        return [$visitor->names, $visitor->loopLocals, $visitor->unknown];
    }
}
