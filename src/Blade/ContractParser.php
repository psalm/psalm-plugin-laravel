<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Extracts a {@see TemplateContract} from a Blade template: `@var`
 * declarations, `@props` entries, and the set of top-level variables the
 * compiled body reads. Read-only; wiring the result into shadow compilation
 * is a separate step.
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

    /**
     * Mirrors `BladeCompiler::compileStatements()`: every `@name` optionally followed by its own
     * balanced `(...)` argument, matched left-to-right over the MASKED source. `preg_match_all()`
     * never backtracks into an already-consumed match, so a directive's FULL argument span — a
     * string, a comment, a nested call — is consumed as part of THAT directive's one match before
     * the scan resumes past it; text spelling `@props` inside another directive's argument
     * (a `@php($x = "@props([...])")` string, a `@props([... 'Use @props here' ...])` string
     * value, a block comment inside a `@props(...)` argument) is therefore never read as a
     * second, independent declaration. `(?<!@)` skips an escaped `@@props`; case-insensitive,
     * like Blade's own directive dispatch.
     */
    private const DIRECTIVE_PATTERN = '/(?<!@)@(?<name>[A-Za-z_]\w*)(?:\s*' . MarkerPrePass::ARGUMENT_PATTERN . ')?/is';

    private ?Parser $parser = null;

    public function parse(string $source, string $compiled): TemplateContract
    {
        [$vars, $propsUnknown] = $this->parseSource($source);

        return new TemplateContract($vars, $this->readVariables($compiled), $propsUnknown);
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
        [$vars, $propsUnknown] = $this->parseSource($source);
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
        [$vars, $propsUnknown] = $this->parseSource($source);

        return new ViewDataContract($vars, $propsUnknown);
    }

    /**
     * Names a template declares in the raw `<?php` docblock spelling, which
     * {@see self::parseSource()} does not read: a raw PHP block reaches the shadow byte-for-byte,
     * so its own docblocks never pass through the Blade-comment scan above.
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
     *
     * @psalm-pure
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
     * @return array{0: array<string, ContractVar>, 1: bool}
     */
    private function parseSource(string $source): array
    {
        $masked = MarkerPrePass::maskedRanges($source);

        // Later byte offset wins on a name collision, same as a document-order walk: a `@props`
        // entry and a `{{-- @var --}}` comment can both declare the same name, and whichever
        // appears LATER in the template is the one the author meant to win.
        /** @var list<array{0: int, 1: string, 2: ContractVar}> $declarations */
        $declarations = [];

        foreach ($masked as [$text, $offset]) {
            // Blade strips comments before it recognises directives, so only the `{{-- --}}`
            // ranges (never a `@verbatim` body, `@php` block, or raw `<?php` tag) are comments.
            if (!\str_starts_with($text, '{{--')) {
                continue;
            }

            $inner = \substr($text, 4, -4);

            if (\preg_match(self::VAR_PATTERN, $inner, $matches) === 1) {
                // Greedy capture keeps a separator space when several precede the
                // variable name; the type string must not carry it.
                $line = 1 + SourceLines::breaksIn($source, 0, $offset);
                $declarations[] = [$offset, $matches[2], new ContractVar($matches[2], \rtrim($matches[1]), $line, false)];
            }
        }

        $maskedSource = MarkerPrePass::blankRanges($source, $masked);
        $propsUnknown = false;

        if (\preg_match_all(self::DIRECTIVE_PATTERN, $maskedSource, $directives, \PREG_OFFSET_CAPTURE) === false) {
            $propsUnknown = true;
        } else {
            /** @var list<array{0: string, 1: int}> $fullMatches */
            $fullMatches = $directives[0];
            /** @var list<array{0: string, 1: int}> $names */
            $names = $directives['name'];
            /** @var list<array{0: string, 1: int}> $argLists */
            $argLists = $directives['args'];

            foreach ($names as $i => [$directiveName]) {
                if (\strtolower($directiveName) !== 'props') {
                    continue;
                }

                $offset = $fullMatches[$i][1];
                [$argsText, $argsOffset] = $argLists[$i];

                if ($argsOffset === -1) {
                    // No `(...)` follows this `@props` at all: unparseable, same as a props array
                    // that isn't fully literal.
                    $propsUnknown = true;

                    continue;
                }

                $rawArgs = \substr($source, $argsOffset + 1, \strlen($argsText) - 2);
                $props = $this->parseProps($rawArgs);

                if ($props === null) {
                    $propsUnknown = true;

                    continue;
                }

                $line = 1 + SourceLines::breaksIn($source, 0, $offset);

                foreach ($props as $propName => $optional) {
                    $declarations[] = [$offset, $propName, new ContractVar($propName, 'mixed', $line, $optional)];
                }
            }
        }

        // Stable since PHP 8.0: several props declared by the SAME `@props(...)` call keep their
        // array order among themselves, and a var/props tie (same byte offset) cannot happen —
        // `{{--` and `@props` never start at the same position.
        \usort($declarations, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $vars = [];

        foreach ($declarations as [, $name, $var]) {
            $vars[$name] = $var;
        }

        return [$vars, $propsUnknown];
    }

    /** One parser for every template in the pass: constructing one re-reads PHP's own token tables. */
    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param string $innerContent the raw source text between `@props(` and the matching `)`,
     *        exactly as it reads in the template
     *
     * @return array<string, bool>|null prop name => has a literal default; null when the array isn't fully literal
     */
    private function parseProps(string $innerContent): ?array
    {
        if ($innerContent === '') {
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
             *
             * @psalm-external-mutation-free
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
