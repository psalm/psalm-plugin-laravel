<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psalm\Internal\Analyzer\CommentAnalyzer;

/**
 * Extracts a {@see TemplateContract} from a Blade template: `@var`
 * declarations (`{{-- --}}` comments, and raw docblocks for names the template does not bind itself),
 * `@props` entries, and the set of top-level variables the
 * compiled body reads. Read-only; wiring the result into shadow compilation
 * is a separate step.
 *
 * @psalm-api
 */
final class ContractParser
{
    /**
     * The `{{-- @var T $name --}}` spelling, matched against a comment's inner content; group 1 is
     * what follows `@var`, for {@see self::splitVar()}. One line, as it always was.
     *
     * Public because {@see Annotate\TemplateAnnotator} has to recognise exactly what this recognises.
     */
    public const VAR_PATTERN = '/^\s*@var\s+([^\r\n]+?)\s*$/';

    /** PHP's own variable-name grammar, as bytes. */
    public const IDENTIFIER = '[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*';

    /**
     * What follows `@var` on one line of a comment inside a raw PHP block, minus a closing `*\/`.
     * Per line, because one block can hold several docblocks.
     */
    private const RAW_PHP_VAR = '/@var\h+(.+?)\h*(?:\*\/)?\h*\r?$/m';

    /**
     * Mirrors `BladeCompiler::compileStatements()`'s own tokenizer regex
     * (`/\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ( [\S\s]*? ) \))?/x`), matched left-to-right over the
     * MASKED source: `\B@` (not `(?<!@)`) so a mid-word `@` — `a@example(...)` — is never read as
     * a directive, `[ \t]*` (not `\s*`) so only SAME-LINE whitespace separates the name from its
     * args — a blank line's worth of unrelated, later parens can never be misread as THIS
     * directive's argument — and an optional leading `@` inside the name captures Blade's own
     * `@@props` escape (`str_contains($match[1], '@')` in `compileStatement()`) without a
     * lookbehind, which can't tell `@@props` from a SECOND escaped `@@@props` apart.
     *
     * `preg_match_all()` never backtracks into an already-consumed match, so a directive's FULL
     * argument span — a string, a comment, a nested call — is consumed as part of THAT
     * directive's one match before the scan resumes past it; text spelling `@props` inside
     * another directive's argument (a `@php($x = "@props([...])")` string, a
     * `@props([... 'Use @props here' ...])` string value, a block comment inside a `@props(...)`
     * argument) is therefore never read as a second, independent declaration. Case-insensitive,
     * like Blade's own directive dispatch.
     */
    private const DIRECTIVE_PATTERN = '/\B@(?<name>@?\w+(?:::\w+)?)(?:[ \t]*' . MarkerPrePass::ARGUMENT_PATTERN . ')?/is';

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
     * The compiled output is only read here, never fed back into it.
     */
    public function parseDataContract(string $source, string $compiled): ViewDataContract
    {
        [$vars, $propsUnknown] = $this->parseSource($source);
        [$reads, $readsUnknown, $localVariables] = $this->parseReads($compiled);

        $vars += $this->rawContractVars($source, $compiled, $localVariables);

        // What is left is consumed-only: a raw declaration of a name the template binds for itself,
        // a Blade-owned name, a non-docblock comment, or a name-first `@var $x T`.
        $rawDeclared = \array_values(\array_diff(self::rawDeclaredNames($source), \array_keys($vars)));

        return new ViewDataContract($vars, $propsUnknown, $reads, $readsUnknown, $localVariables, $rawDeclared);
    }

    /**
     * The declaration half of {@see self::parse()}, from the template source alone, so it can run
     * before compilation: its `@var` types also seed the shadow prelude
     * ({@see BladeBootstrapper::bodyTypes()}).
     */
    public function parseDeclarations(string $source): ViewDataContract
    {
        [$vars, $propsUnknown] = $this->parseSource($source);

        return new ViewDataContract($vars, $propsUnknown);
    }

    /**
     * {@see self::parseDeclarations()} plus the raw `@var` docblocks, for a run that does not
     * collect the read set. Raw declarations need the compiled body to tell view data from a type
     * hint on a local, so it is only walked when the source mentions a `@var` at all.
     */
    public function withRawDeclarations(ViewDataContract $declarations, string $source, string $compiled): ViewDataContract
    {
        if (!\str_contains($source, '@var')) {
            return $declarations;
        }

        return new ViewDataContract(
            $declarations->vars + $this->rawContractVars($source, $compiled, $this->parseReads($compiled)[2]),
            $declarations->propsUnknown,
        );
    }

    /**
     * Names a template declares in the raw docblock spelling, which {@see self::parseSource()} does
     * not read: a raw PHP block reaches the shadow byte-for-byte, so its own docblocks never pass
     * through the Blade-comment scan above.
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
        return \array_column(self::rawDeclarations($source), 0);
    }

    /**
     * Every raw `@var T $name` docblock in the template that is view data rather than a local: a
     * template that writes one for a name it binds itself (`$member` after `@foreach ($members as
     * $member)`) is typing a local, which no call site passes, and one for a Blade-owned name
     * (`$errors`, `$slot`) is the IDE idiom for a variable Blade supplies. Only a `/** *\/` docblock
     * counts, the one Psalm reads a `@var` from.
     *
     * A declaration is optional, the way a `@props` default is, when its type includes `null` or is
     * `mixed`, or when the template guards the name itself (`$x ?? ...`, `$x ??= ...`, `isset($x)`):
     * each states that the template copes without a value.
     *
     * @param list<string> $locals names the template binds for itself, see {@see self::parseReads()}
     *
     * @return array<string, ContractVar>
     *
     * @psalm-mutation-free
     */
    private function rawContractVars(string $source, string $compiled, array $locals): array
    {
        $vars = [];

        foreach (self::rawDeclarations($source) as [$name, $type, $line, $isDoc]) {
            if ($type === null || !$isDoc || isset(PreludeBuilder::BLADE_OWNED_NAMES[$name]) || \in_array($name, $locals, true)) {
                continue;
            }

            $optional = $this->isGuarded($name, $compiled);

            foreach ($this->topLevelAlternatives($type) as $alternative) {
                $optional = $optional
                    || \in_array(\strtolower($alternative), ['null', 'mixed'], true)
                    || \str_starts_with($alternative, '?');
            }

            // A later declaration of the same name wins, as in a document-order walk.
            $vars[$name] = new ContractVar($name, $type, $line, $optional, true);
        }

        return $vars;
    }

    /** @psalm-pure */
    private function isGuarded(string $name, string $compiled): bool
    {
        $quoted = \preg_quote($name, '/');
        $end = '(?![a-zA-Z0-9_\x80-\xff])';

        return \preg_match("/\\\${$quoted}{$end}\\s*\\?\\?|isset\\s*\\([^)]*\\\${$quoted}{$end}/", $compiled) === 1;
    }

    /**
     * The `T $name [description]` after a `@var`, split the way Psalm splits it: the type is the
     * first bracket- and quote-balanced token, the name is the `$identifier` right after it, and the
     * rest is a description, whose own `$names` bind nothing (`@var Closure(Foo $f): Bar $cb` binds
     * `$cb`, not `$f`). Null when it does not read that way: no name, a name-first `@var $x T`,
     * unbalanced brackets.
     *
     * `$lenient` is for a Blade comment, which has no description convention and whose broken type
     * (`array<int $x`) must still reach the call-site and prelude checks to be reported: a line the
     * splitter cannot read falls back to binding its last `$name`.
     *
     * Public because {@see Annotate\TemplateAnnotator} must bind the same name the contract does:
     * one that read it differently would append a duplicate declaration for it, forever.
     *
     * @return array{0: string, 1: string}|null name (without `$`), type string
     *
     * @psalm-pure
     */
    public static function splitVar(string $declaration, bool $lenient = false): ?array
    {
        $declaration = \trim($declaration);

        try {
            $parts = CommentAnalyzer::splitDocLine($declaration);
        } catch (\Throwable) {
            $parts = [$declaration];
        }

        if (\count($parts) > 1 && \preg_match('/^\$(' . self::IDENTIFIER . ')\z/', $parts[1], $name) === 1) {
            return [$name[1], $parts[0]];
        }

        if ($lenient && \preg_match('/^(.+)\s+\$(' . self::IDENTIFIER . ')\z/', $declaration, $last) === 1) {
            return [$last[2], \rtrim($last[1])];
        }

        return null;
    }

    /**
     * Each `@var T $name [description]` line in the raw blocks, split the way Psalm splits it (see
     * {@see self::splitVar()}). A name-first `@var $x T` still declares `$x`, with a null type: it is
     * no contract, but a name the template states, which the annotator must not add again.
     *
     * @return list<array{0: string, 1: string|null, 2: int, 3: bool}> name, type string (null when
     *         name-first), 1-based line, whether it sits in a `/** *\/` docblock
     *
     * @psalm-pure
     */
    private static function rawDeclarations(string $source): array
    {
        $declarations = [];

        foreach (self::rawComments($source) as [$comment, $line, $isDoc]) {
            if (\preg_match_all(self::RAW_PHP_VAR, $comment, $matched, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($matched as $match) {
                $declared = self::splitVar($match[1][0]);
                $at = $line + SourceLines::breaksIn($comment, 0, $match[0][1]);

                if ($declared !== null) {
                    $declarations[] = [$declared[0], $declared[1], $at, $isDoc];
                } elseif (\preg_match('/^\$(' . self::IDENTIFIER . ')(?![a-zA-Z0-9_\x80-\xff])/', $match[1][0], $first) === 1) {
                    $declarations[] = [$first[1], null, $at, $isDoc];
                }
            }
        }

        return $declarations;
    }

    /**
     * The top-level `|` alternatives of a type string.
     *
     * @return non-empty-list<string>
     *
     * @psalm-pure
     */
    private function topLevelAlternatives(string $typeString): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        foreach (\str_split($typeString) as $char) {
            $depth += \strpbrk($char, '([{<') !== false ? 1 : (\strpbrk($char, ')]}>') !== false ? -1 : 0);

            if ($char === '|' && $depth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * The text of every comment inside a raw `<?php` block or an `@php ... @endphp` block, and the
     * 1-based line it starts on. Ranges are the ones the Blade compiler itself leaves alone, so a
     * `<?php` inside a Blade comment or `@verbatim` body is not read.
     *
     * @return list<array{0: string, 1: int, 2: bool}> text, 1-based line, whether it is a docblock
     *
     * @psalm-pure
     */
    private static function rawComments(string $source): array
    {
        $comments = [];

        foreach (MarkerPrePass::maskedRanges($source) as [$text, $offset]) {
            if (\str_starts_with($text, '@php')) {
                // `@php` and `@endphp` are directives, not PHP: tokenize what sits between them.
                $text = '<?php ' . \substr($text, 4, \str_ends_with($text, '@endphp') ? -7 : null);
            } elseif (\stripos($text, '<?php') !== 0) {
                continue;
            }

            $line = 1 + SourceLines::breaksIn($source, 0, $offset);

            // The block can be syntactically incomplete (an unclosed `<?php` at EOF). Tokenizing does
            // not parse, so that is fine; the @ is for the warning an unterminated string emits.
            foreach (@\token_get_all($text) as $token) {
                if (\is_array($token) && ($token[0] === \T_DOC_COMMENT || $token[0] === \T_COMMENT)) {
                    $comments[] = [$token[1], $line + $token[2] - 1, $token[0] === \T_DOC_COMMENT];
                }
            }
        }

        return $comments;
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

            $declared = \preg_match(self::VAR_PATTERN, $inner, $matches) === 1 ? self::splitVar($matches[1], true) : null;

            if ($declared !== null && !isset(PreludeBuilder::BLADE_OWNED_NAMES[$declared[0]])) {
                $line = 1 + SourceLines::breaksIn($source, 0, $offset);
                $declarations[] = [$offset, $declared[0], new ContractVar($declared[0], $declared[1], $line, false)];
            }
        }

        $maskedSource = MarkerPrePass::blankRanges($source, $masked);
        $propsUnknown = false;

        /*
         * A raw open-tag (raw PHP, not `@php`) is a HARD boundary a directive's argument can
         * never cross: `BladeCompiler::compileString()` tokenizes the WHOLE template with
         * `token_get_all()` first and runs `compileStatements()` on each resulting `T_INLINE_HTML`
         * token SEPARATELY (`parseToken()`), so a `(` before one of these tags and a `)` after it
         * are never scanned as the same string, let alone the same match. `@verbatim` and
         * `@php...@endphp` are NOT boundaries: `storeUncompiledBlocks()` replaces their whole body
         * with a short inline placeholder BEFORE tokenizing, so the surrounding text stays in the
         * SAME `T_INLINE_HTML` token and a directive's argument can legitimately span across one.
         */
        $segmentStart = 0;
        $hardBoundaries = [];

        foreach ($masked as [$text, $offset]) {
            if (\str_starts_with($text, '{{--') || \str_starts_with($text, '@verbatim') || \str_starts_with($text, '@php')) {
                continue;
            }

            $hardBoundaries[] = [$offset, $offset + \strlen($text)];
        }

        $hardBoundaries[] = [\strlen($source), \strlen($source)];

        foreach ($hardBoundaries as [$hardStart, $hardEnd]) {
            $segment = \substr($maskedSource, $segmentStart, $hardStart - $segmentStart);

            $this->scanPropsDirectives($segment, $segmentStart, $source, $declarations, $propsUnknown);

            $segmentStart = $hardEnd;
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

    /**
     * One segment's worth of {@see self::DIRECTIVE_PATTERN} matches, appending every `@props`
     * entry found to `$declarations` and flagging `$propsUnknown` on anything unparseable.
     *
     * @param list<array{0: int, 1: string, 2: ContractVar}> $declarations
     */
    private function scanPropsDirectives(string $segment, int $segmentOffset, string $source, array &$declarations, bool &$propsUnknown): void
    {
        if (\preg_match_all(self::DIRECTIVE_PATTERN, $segment, $directives, \PREG_OFFSET_CAPTURE) === false) {
            $propsUnknown = true;

            return;
        }

        /** @var list<array{0: string, 1: int}> $fullMatches */
        $fullMatches = $directives[0];
        /** @var list<array{0: string, 1: int}> $names */
        $names = $directives['name'];
        /** @var list<array{0: string, 1: int}> $argLists */
        $argLists = $directives['args'];

        foreach ($names as $i => [$directiveName]) {
            // A leading `@` in the captured name is Blade's own `@@props` escape: the match is
            // STILL consumed (so the scan does not re-enter its argument looking for a nested
            // `@props`), it is simply never a live declaration.
            if (\str_starts_with($directiveName, '@') || \strtolower($directiveName) !== 'props') {
                continue;
            }

            $offset = $segmentOffset + $fullMatches[$i][1];
            [$argsText, $localArgsOffset] = $argLists[$i];

            if ($localArgsOffset === -1) {
                // No `(...)` follows this `@props` at all: unparseable, same as a props array
                // that isn't fully literal.
                $propsUnknown = true;

                continue;
            }

            $argsOffset = $segmentOffset + $localArgsOffset;
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
