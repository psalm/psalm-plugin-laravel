<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
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

    public function parse(string $source, string $compiled): TemplateContract
    {
        [$vars, $suppressions, $propsUnknown] = $this->parseSource($source);

        return new TemplateContract($vars, $this->readVariables($compiled), $suppressions, $propsUnknown);
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

    /**
     * @return array<string, bool>|null prop name => has a literal default; null when the array isn't fully literal
     */
    private function parseProps(DirectiveNode $node): ?array
    {
        $innerContent = $node->arguments?->innerContent;

        if ($innerContent === null || $innerContent === '') {
            return null;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

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
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($compiled) ?? [];
        } catch (\Throwable) {
            return [];
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $names = [];

            /** @var array<string, true> */
            public array $loopLocals = [];

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

                if ($node instanceof Node\Expr\Variable
                    && \is_string($node->name)
                    && $node->getAttribute('contractSkip') !== true
                ) {
                    $this->names[$node->name] = true;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $names = [];

        foreach (\array_keys($visitor->names) as $name) {
            if ($name === 'this'
                || \str_starts_with($name, '__')
                || isset(PreludeBuilder::AMBIENT_TYPES[$name])
                || isset($visitor->loopLocals[$name])
            ) {
                continue;
            }

            $names[] = $name;
        }

        \sort($names);

        return $names;
    }
}
