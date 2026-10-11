<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psalm\Internal\Analyzer\CommentAnalyzer;
use Psalm\Internal\Type\ParseTree;
use Psalm\Internal\Type\ParseTreeCreator;
use Psalm\Internal\Type\TypeTokenizer;

/**
 * Extracts a {@see TemplateContract} from a Blade template: `@var` declarations (raw PHPDoc
 * docblocks in `<?php` / `@php` blocks, for names the template does not bind itself), `@props`
 * entries, and the set of top-level variables the compiled body reads. Read-only; wiring the
 * result into shadow compilation is a separate step.
 *
 * @psalm-api
 */
final class ContractParser
{
    /** PHP's own variable-name grammar, as bytes. */
    private const IDENTIFIER = '[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*';

    /**
     * What follows `@var` on one line of a comment inside a raw PHP block, minus a closing `*\/`.
     * Per line, because one block can hold several docblocks. The tag must start the line (after
     * optional whitespace and a `*`, `//` or `#`) or directly follow the comment's own opener: a `@var` in
     * running text (`@deprecated Replace @var Foo $user with ...`, `* Example: /** @var T $x`) is prose,
     * not a declaration. `(*ANYCRLF)` makes a bare `\r` end a line too, as PHP's lexer counts it.
     */
    private const RAW_PHP_VAR = '/(*ANYCRLF)(?:^\h*(?:\*|\/\/|#)?|\A\/\*+)\h*@var\h+(.+?)\h*(?:\*\/)?\h*$/m';

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

    /**
     * Livewire markup or code: the component's public properties reach its view without any
     * `view()` call or contract naming them.
     */
    private const LIVEWIRE_MARKERS = '/\bwire:[\w.-]+|@this\b|@entangle\b|\$wire\b|\$this\b|\bnew\s+class\b/';

    /** Laravel injects `$message` into every mail view, and `@error` binds it. */
    private const ALWAYS_PROVIDED = ['message' => true];

    private ?Parser $parser = null;

    public function parse(string $source, string $compiled): TemplateContract
    {
        [$vars, $propsUnknown] = $this->parseSource($source);
        [$names, $locals] = $this->walkReads($compiled);

        $vars += $this->rawContractVars($source, $this->filterNames($locals, []));

        return new TemplateContract($vars, $this->filterNames($names, $locals), $propsUnknown);
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

        $vars += $this->rawContractVars($source, $localVariables);

        // What is left is consumed-only: a raw declaration of a name the template binds for itself,
        // a Blade-owned name, a non-docblock comment, or a name-first `@var $x T`.
        $rawDeclared = \array_values(\array_diff(self::rawDeclaredNames($source), \array_keys($vars)));

        return new ViewDataContract($vars, $propsUnknown, $reads, $readsUnknown, $localVariables, $rawDeclared);
    }

    /**
     * The names a contract template's compiled body reads that nothing in the template declares or
     * binds: the ones the prelude may leave undeclared for Psalm to report (#1827). Empty unless the
     * template declares a contract (a `@var` docblock that is view data, or a `@props` entry) and its
     * read set is provably whole.
     *
     * @return list<string>
     */
    public function undeclaredReads(string $source, string $compiled): array
    {
        if (\preg_match(self::LIVEWIRE_MARKERS, $source) === 1) {
            return [];
        }

        [$names, $locals, $unknown, $opaque] = $this->walkReads($compiled);

        if ($unknown || $opaque) {
            return [];
        }

        [$vars] = $this->parseSource($source);
        $vars += $this->rawContractVars($source, $this->filterNames($locals, []));

        if ($vars === []) {
            return [];
        }

        // Every raw `@var` counts, not only contract ones: a name-first or non-doc `@var` still states the name.
        $declared = \array_fill_keys([...\array_keys($vars), ...self::rawDeclaredNames($source)], true);

        return $this->filterNames($names, $locals + $declared + self::ALWAYS_PROVIDED);
    }

    /**
     * The declaration half of {@see self::parse()}: `@props` entries and, when the compiled output is
     * given, the raw `@var` docblocks that are view data. Telling view data from a type hint on a
     * local needs the compiled body, which is only walked when the source mentions a `@var` at all.
     */
    public function parseDeclarations(string $source, ?string $compiled = null): ViewDataContract
    {
        [$vars, $propsUnknown] = $this->parseSource($source);

        if ($compiled !== null && \str_contains($source, '@var')) {
            $vars += self::rawContractVars($source, $this->parseReads($compiled)[2]);
        }

        return new ViewDataContract($vars, $propsUnknown);
    }

    /**
     * Names a template declares in the raw docblock spelling, which {@see self::parseSource()} does
     * not read: a raw PHP block reaches the shadow byte-for-byte.
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
     * A declaration is required unless it ends in `= null` (see {@see self::splitVar()}): a nullable
     * or `mixed` type means "pass null explicitly", not "may be omitted".
     *
     * @param list<string> $locals names the template binds for itself, see {@see self::parseReads()}
     *
     * @return array<string, ContractVar>
     *
     * @psalm-pure
     */
    private function rawContractVars(string $source, array $locals): array
    {
        $vars = [];

        foreach (self::rawDeclarations($source) as [$name, $type, $line, $isDoc, $optional]) {
            if ($type === null || !$isDoc || isset(PreludeBuilder::BLADE_OWNED_NAMES[$name]) || \in_array($name, $locals, true)) {
                continue;
            }

            // A later declaration of the same name wins, as in a document-order walk.
            $vars[$name] = new ContractVar($name, $type, $line, $optional);
        }

        return $vars;
    }

    /**
     * The `T $name [description]` after a `@var`, split the way Psalm splits it: the type is the
     * first bracket- and quote-balanced token, the name is the `$identifier` right after it, and the
     * rest is a description, whose own `$names` bind nothing (`@var Closure(Foo $f): Bar $cb` binds
     * `$cb`, not `$f`). Null when it does not read that way: no name, a name-first `@var $x T`,
     * unbalanced brackets.
     *
     * Public because {@see Annotate\TemplateAnnotator} must bind the same name the contract does:
     * one that read it differently would append a duplicate declaration for it, forever.
     *
     * A description that starts with `= null` marks the variable optional, the one way a template says
     * a call site may omit it: `@var string|null $title = null`. Anything after the `= null` is
     * still description.
     *
     * @return array{0: string, 1: string, 2: bool}|null name (without `$`), type string, optional
     *
     * @psalm-pure
     */
    public static function splitVar(string $declaration): ?array
    {
        $declaration = \trim($declaration);

        try {
            $parts = CommentAnalyzer::splitDocLine($declaration);
        } catch (\Throwable) {
            $parts = [$declaration];
        }

        if (\count($parts) > 1 && \preg_match('/^\$(' . self::IDENTIFIER . ')\z/', $parts[1], $name) === 1) {
            return [$name[1], $parts[0], \preg_match('/^=\s*null(?=\s|\*\/|\z)/i', \implode(' ', \array_slice($parts, 2))) === 1];
        }

        return null;
    }

    /**
     * Whether a type string includes `null` (or is `mixed`), read off Psalm's type parse tree so a
     * bracketed `(string|null)` or a `?T` counts and a `null` nested in a generic does not. False for
     * a type that does not parse.
     */
    public static function allowsNull(string $type): bool
    {
        try {
            $tree = (new ParseTreeCreator(TypeTokenizer::tokenize($type)))->create();
        } catch (\Throwable) {
            return false;
        }

        return self::nodeAllowsNull($tree);
    }

    /** @psalm-mutation-free */
    private static function nodeAllowsNull(ParseTree $node): bool
    {
        if ($node instanceof ParseTree\Value) {
            return \in_array(\strtolower($node->value), ['null', 'mixed'], true);
        }

        if ($node instanceof ParseTree\NullableTree) {
            return true;
        }

        if ($node instanceof ParseTree\UnionTree || $node instanceof ParseTree\EncapsulationTree || $node instanceof ParseTree\Root) {
            foreach ($node->children as $child) {
                if (self::nodeAllowsNull($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * `$type` with `null` added, for an optional declaration: `T $x = null` reads as `T|null`. A type
     * that already allows null is returned as is; a callable or intersection type is parenthesised so
     * the `|null` does not become part of it.
     */
    public static function withNull(string $type): string
    {
        if (self::allowsNull($type)) {
            return $type;
        }

        return (\str_contains($type, '(') || \str_contains($type, '&') ? "({$type})" : $type) . '|null';
    }

    /**
     * Each `@var T $name [description]` line in the raw blocks, split the way Psalm splits it (see
     * {@see self::splitVar()}). A name-first `@var $x T` still declares `$x`, with a null type: it is
     * no contract, but a name the template states, which the annotator must not add again.
     *
     * @return list<array{0: string, 1: string|null, 2: int, 3: bool, 4: bool}> name, type string (null when
     *         name-first), 1-based line, whether it sits in a `/** *\/` docblock, whether it ends in `= null`
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
                    $declarations[] = [$declared[0], $declared[1], $at, $isDoc, $declared[2]];
                } elseif (\preg_match('/^\$(' . self::IDENTIFIER . ')(?![a-zA-Z0-9_\x80-\xff])/', $match[1][0], $first) === 1) {
                    $declarations[] = [$first[1], null, $at, $isDoc, false];
                }
            }
        }

        return $declarations;
    }

    /**
     * The text of every comment inside a raw `<?php` block or an `@php ... @endphp` block that can
     * declare a variable, the 1-based line it starts on, and the line its PHP block opens on. A doc
     * comment counts only at statement level ({@see PhpDocBlocks}): a property, method, function or
     * closure docblock is no declaration. A plain comment is kept (not a doc one), so a name it
     * mentions still counts as stated. Ranges are the ones the Blade compiler itself leaves alone,
     * so a `<?php` inside a Blade comment or `@verbatim` body is not read.
     *
     * @return list<array{0: string, 1: int, 2: bool, 3: int}> text, 1-based line, whether it is a
     *         docblock, 1-based line of the enclosing block's opening
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
            $tokens = @\token_get_all($text);
            $docblocks = PhpDocBlocks::statementLevel($tokens);

            foreach ($tokens as $i => $token) {
                if (!\is_array($token)) {
                    continue;
                }

                if ($token[0] === \T_COMMENT || isset($docblocks[$i])) {
                    $comments[] = [$token[1], $line + $token[2] - 1, $token[0] === \T_DOC_COMMENT, $line];
                }
            }
        }

        return $comments;
    }

    /**
     * What suppresses an issue reported on a declaration line: the issue types a `@psalm-suppress` tag
     * in the declaring docblock names, and the line its PHP block opens on, where a Blade
     * `{{-- @psalm-suppress --}}` comment above a multi-line block is recorded.
     *
     * @return array{0: list<string>, 1: int}
     *
     * @psalm-pure
     */
    public static function suppressionContext(string $source, int $line): array
    {
        foreach (self::rawComments($source) as [$comment, $start, $isDoc, $blockLine]) {
            if (!$isDoc || $line < $start || $line > $start + SourceLines::breaksIn($comment)) {
                continue;
            }

            \preg_match_all('/@psalm-suppress\h+([A-Za-z][A-Za-z0-9_]*)/', $comment, $matched);

            return [$matched[1], $blockLine];
        }

        return [[], $line];
    }

    /**
     * @return array{0: array<string, ContractVar>, 1: bool}
     */
    private function parseSource(string $source): array
    {
        $masked = MarkerPrePass::maskedRanges($source);

        /** @var list<array{0: int, 1: string, 2: ContractVar}> $declarations */
        $declarations = [];

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
        // array order among themselves; a later `@props` entry wins a name collision.
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
     * The read set the UnusedViewData rule consumes. Two differences from {@see self::parse()}'s read set:
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
     * @return array{0: array<string, true>, 1: array<string, true>, 2: bool, 3: bool} names read,
     *         the names the body binds for itself, whether it hides which names it reads, and whether
     *         it hands its scope to code no walk sees (`include`, `eval`)
     */
    private function walkReads(string $compiled): array
    {
        $parser = $this->parser();

        try {
            $ast = $parser->parse($compiled) ?? [];
        } catch (\Throwable) {
            // Unparseable, not empty: an empty read set reads as "the template reads nothing", which
            // would turn every key a call site passes into a false positive.
            return [[], [], true, true];
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $names = [];

            /** @var array<string, true> */
            public array $locals = [];

            public bool $unknown = false;

            public bool $opaque = false;

            #[\Override]
            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Eval_) {
                    $this->opaque = true;
                }

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
                // `$row['k'] = …`, `$row[] = …` and `$a['x']['y'] = …` create `$row` / `$a` when it is
                // unset, so the template binds it for itself, whatever it holds afterwards.
                while ($target instanceof Node\Expr\ArrayDimFetch) {
                    $target = $target->var;
                }

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

        return [$visitor->names, $visitor->locals, $visitor->unknown, $visitor->opaque];
    }
}
