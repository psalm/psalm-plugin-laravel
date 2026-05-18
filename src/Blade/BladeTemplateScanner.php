<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Compiles a Blade template via {@see PsalmBladeCompiler} and walks the resulting
 * PHP AST to classify the template as SAFE, UNSAFE_KEYS, or UNKNOWN.
 *
 * The scanner avoids regex-based Blade parsing entirely. Laravel's
 * {@see \Illuminate\View\Compilers\BladeCompiler} already maps every Blade
 * directive ({@code @foreach}, {@code @if}, {@code @php}, {@code @extends},
 * {@code @include}, etc.) to a small, deterministic set of PHP statements:
 *
 *  - `{{ $x }}`               -> `echo e($x)`
 *  - `{!! $x !!}`             -> `echo $x`
 *  - `{{{ $x }}}`             -> `echo e($x)` (legacy triple-brace)
 *  - `@foreach (...)`         -> `foreach (...): ... endforeach;`
 *  - `@php ... @endphp`       -> `<?php ... ?>`
 *  - `@extends(...)`          -> `$__env->make(...)->render()`
 *  - `@yield(...)`            -> `echo $__env->yieldContent(...)`
 *  - `@stack(...)`            -> `echo $__env->yieldPushContent(...)`
 *  - `@include(...)`          -> `echo $__env->make(...)->render()`
 *  - `@inject(...)`           -> `$name = app(...)`
 *
 * Walking the resulting AST gives perfect handling of constructs that broke the
 * regex backend: string literals containing `@yield(...)` inside `@php` blocks
 * (compiled away), word-boundary issues between `@push` / `@pushOnce` (separate
 * AST nodes), `@verbatim` regions ({{ }} stays literal in HTML output, never
 * reaches an `echo` node), comments (compiled away), and inline assignments
 * `@if ($x = expr)` ({@see Node\Expr\Assign} inside {@see Node\Stmt\If_::$cond}
 * is plainly visible to the visitor).
 *
 * Compiler / parser instances are injected so tests can substitute fakes and so
 * a long-lived {@see BladeSafetyMap::build()} pass can reuse one parser across
 * many templates. Both constructor arguments default to fresh instances built
 * with no Laravel container access, so the scanner has no boot-time dependency
 * on the plugin's Testbench-backed application.
 *
 * Purity: the scanner is *not* marked `@psalm-pure` or `@psalm-mutation-free`.
 * {@see PsalmBladeCompiler::compileBladeSource()} mutates the underlying
 * BladeCompiler instance (footers, raw blocks, sawComponentTag flag), and the
 * visitor maintains intermediate state. Each call to {@see analyze()} resets
 * compiler state and constructs a fresh visitor, so externally the scanner
 * behaves like a deterministic function from `$source` to `BladeTemplateAnalysis`.
 *
 * Known precision limitation: {@see BladeAstAnalysisVisitor} maintains a flat
 * `scopeLocals` set. NodeTraverser visits inner closures, arrow functions,
 * function declarations, and dead branches, so an assignment inside any of
 * those still registers the LHS name as a scope-local for the whole template.
 * The practical impact is a false-negative when a closure body shadows the
 * name of a view-data key that is also raw-echoed at the top level. Adding
 * push/pop scope frames is scoped to a follow-up PR.
 *
 * @psalm-api
 */
final class BladeTemplateScanner
{
    /** Function names whose echo result is auto-escaped HTML. */
    private const SAFE_HTML_WRAPPER_FUNCTIONS = ['e', 'htmlspecialchars', 'htmlentities'];

    /**
     * Static-method names treated as safe when called on a class named `Js`
     * (Laravel's {@see \Illuminate\Support\Js} helper). Imported as either
     * `use Illuminate\Support\Js;` (bare `Js::from`) or with the full
     * namespace, so the scanner matches both shapes by short-name.
     */
    private const SAFE_JS_HELPER_METHOD = 'from';

    /**
     * Auto-generated locals produced by the BladeCompiler in compiled output.
     * These are never view-data keys, so a raw echo of e.g. `$loop->index`
     * must not surface `loop` as an unsafe key.
     */
    private const FRAMEWORK_LOCALS = [
        '__env' => true,
        '__data' => true,
        '__path' => true,
        '__currentLoopData' => true,
        'loop' => true,
        '__empty_1' => true,
    ];

    /**
     * `$__env` method names that signal layout / section / fragment /
     * translation / stack flow. Mapped to
     * {@see BladeUncertaintyReason::LayoutSectionFlow}.
     *
     *  - `@yield`, `@parent` -> yieldContent
     *  - `@section`, `@show`, `@overwrite`, `@stop`, `@endsection`
     *    -> startSection / stopSection / yieldSection / appendSection
     *  - `@hasStack` -> isStackEmpty
     *  - `@fragment`, `@endfragment` -> startFragment / stopFragment / renderFragment
     *  - `@lang { ... } @endlang` -> startTranslation / renderTranslation
     */
    private const ENV_LAYOUT_METHODS = [
        'yieldContent', 'yieldSection', 'startSection', 'stopSection',
        'appendSection', 'isStackEmpty',
        'startFragment', 'stopFragment', 'renderFragment',
        'startTranslation', 'renderTranslation',
    ];

    /**
     * `$__env` method names emitted by `@component` / `@slot` directives.
     * Mapped to {@see BladeUncertaintyReason::ComponentTag} so downstream
     * handlers can apply component-specific fallback policy (e.g. trace
     * attribute / slot data flow) distinct from section-flow policy.
     */
    private const ENV_COMPONENT_METHODS = [
        'startComponent', 'renderComponent', 'slot',
    ];

    /**
     * `$__env` method names that signal an include / partial render.
     * `@include`, `@includeIf`, `@includeFirst`, `@each` all map to one of
     * these. `first` is the method called by `@extendsFirst` and
     * `@includeFirst` (compiled to `$__env->first([...])->render()`).
     */
    private const ENV_INCLUDE_METHODS = [
        'make', 'first', 'renderEach', 'renderWhen', 'renderUnless', 'renderFirst',
    ];

    /**
     * `$__env` method names for the stack / push / prepend family. These
     * compile to `$__env->yieldPushContent` / `$__env->startPush` etc., and
     * any presence implies a cross-template stack flow. Mapped to the same
     * {@see BladeUncertaintyReason::LayoutSectionFlow} reason because the
     * fallback policy for both is identical.
     */
    private const ENV_STACK_METHODS = [
        'yieldPushContent', 'yieldPushContentFirst',
        'startPush', 'stopPush', 'startPrepend', 'stopPrepend',
        'startPushOnce', 'stopPushOnce',
        'startPrependOnce', 'stopPrependOnce',
    ];

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly PsalmBladeCompiler $compiler = new PsalmBladeCompiler(),
        private readonly Parser $parser = new \PhpParser\Parser\Php8(new \PhpParser\Lexer()),
    ) {}

    /**
     * Default-construction helper for callers that want the standard parser
     * (newest supported PHP version) without importing {@see ParserFactory}.
     *
     * The named-default in the constructor is intentionally a {@see Parser\Php8}
     * direct construction (cheap) rather than `ParserFactory::createForNewestSupportedVersion()`,
     * which allocates a Lexer and walks a version-detection path. Tests that
     * need the newest-PHP parser can call this helper.
     *
     * @psalm-api
     */
    public static function withDefaults(): self
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        return new self(new PsalmBladeCompiler(), $parser);
    }

    /**
     * Analyze a Blade template source and return the tri-state safety result.
     *
     * Pipeline:
     *   1. {@see PsalmBladeCompiler::compileBladeSource()} produces compiled PHP.
     *      Any compile-time exception (malformed Blade, unbalanced directives)
     *      maps to UNKNOWN(UNPARSABLE_PHP_BLOCK).
     *   2. {@see Parser::parse()} produces an AST. Parser errors (extremely
     *      unlikely on BladeCompiler output, but possible if an `@php` block
     *      contains malformed PHP) map to the same UNKNOWN reason.
     *   3. {@see BladeAstAnalysisVisitor} walks the AST, collecting:
     *      - top-level variables raw-echoed (unsafe keys, after filtering
     *        scope-locals and framework locals);
     *      - `$__env` method calls indicating layout/section/stack/include flow;
     *      - the {@see PsalmBladeCompiler::sawComponentTag()} flag for `<x-...>`
     *        and `@component` / `@slot` directives.
     *   4. Uncertainties dominate: any uncertainty produces UNKNOWN; otherwise
     *      a non-empty unsafe-key list produces UNSAFE_KEYS; otherwise SAFE.
     */
    public function analyze(string $source): BladeTemplateAnalysis
    {
        if ($this->hasUnclosedPhpBlock($source)) {
            /*
             * BladeCompiler does NOT throw on an unclosed `@php` block: the
             * raw-block storage regex requires a matching `@endphp` and
             * silently leaves an unclosed `@php` as literal text in the
             * compiled output, where it becomes inline HTML in the AST.
             * That would let any taint inside the unclosed region slip
             * through as SAFE. Detect the imbalance ourselves and emit
             * UNKNOWN(UNPARSABLE_PHP_BLOCK).
             */
            return BladeTemplateAnalysis::unknown([BladeUncertaintyReason::UnparsablePhpBlock]);
        }

        try {
            $compiled = $this->compiler->compileBladeSource($source);
        } catch (\Throwable) {
            return BladeTemplateAnalysis::unknown([BladeUncertaintyReason::UnparsablePhpBlock]);
        }

        try {
            /*
             * BladeCompiler emits PHP open/close tags interleaved with
             * literal HTML. The parser accepts that as a normal mixed-mode
             * PHP file; no manual prefix needed.
             */
            $ast = $this->parser->parse($compiled);
        } catch (PhpParserError) {
            return BladeTemplateAnalysis::unknown([BladeUncertaintyReason::UnparsablePhpBlock]);
        }

        if ($ast === null) {
            return BladeTemplateAnalysis::unknown([BladeUncertaintyReason::UnparsablePhpBlock]);
        }

        $visitor = new BladeAstAnalysisVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $uncertainties = $visitor->uncertainties;

        if ($this->compiler->sawComponentTag()
            && !\in_array(BladeUncertaintyReason::ComponentTag, $uncertainties, true)) {
            $uncertainties[] = BladeUncertaintyReason::ComponentTag;
        }

        $unsafeKeys = $visitor->computeUnsafeKeys(self::FRAMEWORK_LOCALS);
        $includeEdges = $visitor->computeIncludeEdges(self::FRAMEWORK_LOCALS);

        if ($uncertainties !== []) {
            return BladeTemplateAnalysis::unknown($uncertainties, $unsafeKeys, $includeEdges);
        }

        return BladeTemplateAnalysis::unsafeKeys($unsafeKeys);
    }

    /**
     * True when the source contains a multi-line `@php` directive that has
     * no matching `@endphp`. The check matches BladeCompiler's own
     * `storePhpBlocks` regex (which only stores blocks that are properly
     * closed), so a leftover unclosed `@php` would otherwise be passed
     * through as literal text.
     *
     * The inline `@php(...)` directive is excluded from the count because it
     * is a single-expression form with no `@endphp` partner.
     *
     * @psalm-pure
     */
    private function hasUnclosedPhpBlock(string $source): bool
    {
        $openCount = \preg_match_all('/(?<!@)@php\b(?!\s*\()/', $source);
        $closeCount = \preg_match_all('/(?<!@)@endphp\b/', $source);

        return $openCount !== false && $closeCount !== false && $openCount > $closeCount;
    }

    /**
     * Lower-level helper: return every variable reference observed inside an
     * `echo` / `print` / `@php` block, with line numbers.
     *
     * Line numbers reflect the *compiled* PHP, not the original Blade source.
     * BladeCompiler does not preserve a complete source map, so per-occurrence
     * line tracking is approximate. Callers using {@see scan()} for
     * diagnostics should treat the line number as a hint, not a contract.
     *
     * @return list<BladeVariableUsage>
     */
    public function scan(string $source): array
    {
        try {
            $compiled = $this->compiler->compileBladeSource($source);
        } catch (\Throwable) {
            return [];
        }

        try {
            $ast = $this->parser->parse($compiled);
        } catch (PhpParserError) {
            return [];
        }

        if ($ast === null) {
            return [];
        }

        $visitor = new BladeAstAnalysisVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->usages;
    }

    /**
     * Used by {@see BladeAstAnalysisVisitor} to decide whether a call wraps
     * its argument in HTML-escape semantics. Kept on the scanner so the list
     * of safe wrappers is defined exactly once.
     *
     * @return list<string>
     *
     * @internal
     *
     * @psalm-pure
     */
    public static function safeHtmlWrapperFunctions(): array
    {
        return self::SAFE_HTML_WRAPPER_FUNCTIONS;
    }

    /**
     * @internal
     *
     * @psalm-pure
     */
    public static function safeJsHelperMethod(): string
    {
        return self::SAFE_JS_HELPER_METHOD;
    }

    /**
     * @return list<string>
     *
     * @internal
     *
     * @psalm-pure
     */
    public static function envLayoutMethods(): array
    {
        return self::ENV_LAYOUT_METHODS;
    }

    /**
     * @return list<string>
     *
     * @internal
     *
     * @psalm-pure
     */
    public static function envIncludeMethods(): array
    {
        return self::ENV_INCLUDE_METHODS;
    }

    /**
     * @return list<string>
     *
     * @internal
     *
     * @psalm-pure
     */
    public static function envStackMethods(): array
    {
        return self::ENV_STACK_METHODS;
    }

    /**
     * @return list<string>
     *
     * @internal
     *
     * @psalm-pure
     */
    public static function envComponentMethods(): array
    {
        return self::ENV_COMPONENT_METHODS;
    }
}

/**
 * Visits the compiled-Blade AST and accumulates the data needed to build a
 * {@see BladeTemplateAnalysis}.
 *
 * The visitor owns state across one walk only; the parent scanner constructs a
 * fresh visitor per `analyze()` / `scan()` call. State fields are public so
 * the scanner can read them directly after traversal; no setter ceremony.
 *
 * @internal
 */
final class BladeAstAnalysisVisitor extends NodeVisitorAbstract
{
    /** @var list<BladeUncertaintyReason> */
    public array $uncertainties = [];

    /** @var list<BladeVariableUsage> */
    public array $usages = [];

    /** @var array<string, true> Variables introduced by assignments / foreach value-vars / etc. */
    private array $scopeLocals = [];

    /** @var array<string, true> Top-level variable names raw-echoed in the template. */
    private array $rawTopVars = [];

    /** @var array<string, true> reason-name => true, dedup uncertainty set */
    private array $seenUncertainty = [];

    /**
     * Raw `@include` edges collected during traversal. Each entry records the
     * literal child view name and, for the 3-argument compileInclude form, the
     * unfiltered top-level variables present in each explicit-data-array entry.
     * Filtering (scope-locals / framework-locals) is deferred to
     * {@see computeIncludeEdges()} because scope-local discovery completes
     * only after the whole AST has been walked.
     *
     * @var list<array{view: non-empty-string, explicit: array<non-empty-string, list<string>>|null}>
     */
    private array $rawIncludeEdges = [];

    #[\Override]
    public function enterNode(Node $node): ?int
    {
        // Track scope-locals from assignment LHS. Covers `@inject` (Assign),
        // inline `@if ($x = expr)` (Assign inside cond), `$__currentLoopData`
        // setup, and the user's own `@php $foo = expr; @endphp`.
        if ($node instanceof Node\Expr\Assign) {
            $this->captureScopeLocalFromAssign($node);
        }

        if ($node instanceof Node\Stmt\Foreach_) {
            $this->captureForeachLocals($node);
        }

        if ($node instanceof Node\Stmt\Echo_) {
            foreach ($node->exprs as $expr) {
                $this->classifyEcho($expr, $node->getStartLine());
            }
        }

        if ($node instanceof Node\Expr\Print_) {
            $this->classifyEcho($node->expr, $node->getStartLine());
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $this->classifyEnvMethodCall($node);
        }

        return null;
    }

    /**
     * Materialise the unsafe-keys list, filtering scope-locals and framework
     * locals. Called once by the scanner after traversal.
     *
     * @param array<string, true> $frameworkLocals
     *
     * @return list<non-empty-string>
     *
     * @psalm-mutation-free
     */
    public function computeUnsafeKeys(array $frameworkLocals): array
    {
        /** @var list<non-empty-string> $keys */
        $keys = [];

        foreach (\array_keys($this->rawTopVars) as $name) {
            if ($name === '') {
                continue;
            }

            if (isset($this->scopeLocals[$name])) {
                continue;
            }

            if (isset($frameworkLocals[$name])) {
                continue;
            }

            $keys[] = $name;
        }

        return $keys;
    }

    private function classifyEcho(Node\Expr $expr, int $line): void
    {
        $kind = $this->isSafeHtmlWrappedCall($expr) ? BladeEchoKind::Escaped : BladeEchoKind::Raw;
        $names = $this->extractTopLevelVariables($expr);

        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }

            $this->usages[] = new BladeVariableUsage(
                $name,
                \max(1, $line),
                $kind,
            );

            if ($kind === BladeEchoKind::Raw) {
                $this->rawTopVars[$name] = true;
            }
        }

        /*
         * Conservative fallback: if a RAW echo's expression yielded no
         * top-level variables and is not a plain literal, the walker is
         * looking at an expression shape it does not model. Examples that
         * hit this branch:
         *   {!! request()->input('html') !!}     // global helper
         *   {!! Auth::user()->bio !!}            // static-call chain
         *   {!! $$dynamicName !!}                // variable-variable
         *   {!! (fn () => $x)() !!}              // closure result
         * Silently classifying these as SAFE would be a security regression
         * (the closed-set extractor would otherwise let canonical XSS
         * sources bypass the analysis), so we mark the template UNKNOWN
         * with a precise reason.
         */
        if ($kind === BladeEchoKind::Raw && $names === [] && !$this->isPureLiteralExpression($expr)) {
            $this->addUncertainty(BladeUncertaintyReason::UnknownLocalDependency);
        }
    }

    /**
     * True for expressions whose value is statically known to be a literal:
     * scalar literals, `null` / `true` / `false`, and class constants. Used
     * by {@see classifyEcho()} to distinguish "raw echo of a constant"
     * (genuinely safe) from "raw echo we don't understand" (must be UNKNOWN).
     *
     * @psalm-pure
     */
    private function isPureLiteralExpression(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Scalar\String_) {
            return true;
        }

        if ($expr instanceof Node\Scalar\Int_ || $expr instanceof Node\Scalar\Float_) {
            return true;
        }

        return $expr instanceof Node\Expr\ConstFetch || $expr instanceof Node\Expr\ClassConstFetch;
    }

    /*
     * Not annotated `@psalm-pure` or `@psalm-mutation-free`: the helper
     * reads {@see Node\Name::toLowerString()} and {@see Node\Name::getLast()},
     * which php-parser does not mark pure. Callers can still be marked
     * external-mutation-free because Psalm treats this method as impure
     * only inside fully-pure contexts.
     */
    private function isSafeHtmlWrappedCall(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            $name = $expr->name->toLowerString();

            if (!\in_array($name, BladeTemplateScanner::safeHtmlWrapperFunctions(), true)) {
                return false;
            }

            /*
             * Only the single-argument form is safe. `e($x, false)` disables
             * double-encoding (allowing pre-escaped `&` to slip through);
             * `htmlspecialchars($x, ENT_NOQUOTES)` does not escape quotes,
             * leaving HTML-attribute contexts vulnerable. Conservatively
             * fall through to RAW when extra arguments are present so the
             * inner variable surfaces as unsafe.
             */
            return \count($expr->args) === 1;
        }

        if ($this->isJsFromStaticCall($expr)) {
            return true;
        }

        /*
         * Chained method calls on a Js::from result (e.g.
         * `Js::from($data)->toHtml()`) remain HTML-safe because every
         * method on the Js helper escapes its output. Surfacing the
         * receiver chain again here would re-leak `$data`.
         */
        return $expr instanceof Node\Expr\MethodCall
            && $this->isJsFromStaticCall($expr->var);
    }

    private function isJsFromStaticCall(Node\Expr $expr): bool
    {
        if (!($expr instanceof Node\Expr\StaticCall)) {
            return false;
        }

        if (!($expr->class instanceof Node\Name)) {
            return false;
        }

        if (!($expr->name instanceof Node\Identifier)) {
            return false;
        }

        $shortName = $expr->class->getLast();
        $method = $expr->name->toString();

        // `Js::from` or `\Illuminate\Support\Js::from`. Short-name match is
        // intentional: an aliased `use Illuminate\Support\Js as J;` would
        // not satisfy a strict FQCN check.
        return $shortName === 'Js' && $method === BladeTemplateScanner::safeJsHelperMethod();
    }

    /**
     * Recursively extract top-level variable names from an expression tree.
     *
     * "Top-level" means the variable that appears as the root of a chain of
     * property/array/method accesses. `$user->bio` yields `user`;
     * `$data['html']` yields `data`; `$x . $y` yields both `x` and `y`;
     * `"hello {$attacker}"` yields `attacker`.
     *
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    private function extractTopLevelVariables(Node\Expr $expr): array
    {
        if ($expr instanceof Node\Expr\Variable && \is_string($expr->name)) {
            return [$expr->name];
        }

        if ($expr instanceof Node\Expr\PropertyFetch
            || $expr instanceof Node\Expr\NullsafePropertyFetch
            || $expr instanceof Node\Expr\ArrayDimFetch
        ) {
            return $this->extractTopLevelVariables($expr->var);
        }

        if ($expr instanceof Node\Expr\StaticPropertyFetch) {
            // `Class::$prop` — the class name is a Node\Name, not a variable.
            // No top-level data flows through this construct, so return empty.
            return [];
        }

        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            /*
             * For `$svc->render($body)`, the receiver `$svc` is one top-level
             * input and each argument expression contributes additional
             * top-level inputs. When the receiver is a scope-local (e.g. an
             * injected service), the call's result is opaque, so any
             * view-data argument should still surface as unsafe.
             */
            $names = $this->extractTopLevelVariables($expr->var);

            foreach ($expr->args as $arg) {
                if ($arg instanceof Node\Arg) {
                    $names = [...$names, ...$this->extractTopLevelVariables($arg->value)];
                }
            }

            return $names;
        }

        if ($expr instanceof Node\Expr\BinaryOp) {
            return [
                ...$this->extractTopLevelVariables($expr->left),
                ...$this->extractTopLevelVariables($expr->right),
            ];
        }

        if ($expr instanceof Node\Expr\Ternary) {
            $names = $this->extractTopLevelVariables($expr->cond);
            if ($expr->if instanceof Node\Expr) {
                $names = [...$names, ...$this->extractTopLevelVariables($expr->if)];
            }

            $names = [...$names, ...$this->extractTopLevelVariables($expr->else)];

            return $names;
        }

        if ($expr instanceof Node\Scalar\InterpolatedString) {
            $names = [];
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\Expr) {
                    $names = [...$names, ...$this->extractTopLevelVariables($part)];
                }
            }

            return $names;
        }

        if ($expr instanceof Node\Expr\Cast
            || $expr instanceof Node\Expr\BooleanNot
            || $expr instanceof Node\Expr\BitwiseNot
            || $expr instanceof Node\Expr\UnaryMinus
            || $expr instanceof Node\Expr\UnaryPlus
        ) {
            /*
             * Single-operand operators ((string)$x, !$x, ~$x, -$x). Type
             * casts do not sanitize HTML, so `(string) $tainted` flowing
             * into a raw echo must surface `tainted` as unsafe.
             */
            return $this->extractTopLevelVariables($expr->expr);
        }

        if ($expr instanceof Node\Expr\Array_) {
            $names = [];
            foreach ($expr->items as $item) {
                if (!($item instanceof Node\ArrayItem)) {
                    // Skipped slots in destructuring shapes such as `[, $b]`
                    // surface as `null` in the items list.
                    continue;
                }

                $names = [...$names, ...$this->extractTopLevelVariables($item->value)];
                if ($item->key instanceof Node\Expr) {
                    $names = [...$names, ...$this->extractTopLevelVariables($item->key)];
                }
            }

            return $names;
        }

        if ($expr instanceof Node\Expr\Match_) {
            $names = $this->extractTopLevelVariables($expr->cond);
            foreach ($expr->arms as $arm) {
                $names = [...$names, ...$this->extractTopLevelVariables($arm->body)];

                if ($arm->conds !== null) {
                    foreach ($arm->conds as $cond) {
                        $names = [...$names, ...$this->extractTopLevelVariables($cond)];
                    }
                }
            }

            return $names;
        }

        if ($expr instanceof Node\Expr\FuncCall || $expr instanceof Node\Expr\StaticCall || $expr instanceof Node\Expr\New_) {
            // Unknown call inside an echo — conservatively treat its arguments
            // as raw-echoed inputs. This is the "treat unknown calls as
            // unsafe" rule from the design doc; callers can later add
            // taint-escape annotations to specific helpers (out of scope for
            // this PR).
            $names = [];
            foreach ($expr->args as $arg) {
                if ($arg instanceof Node\Arg) {
                    $names = [...$names, ...$this->extractTopLevelVariables($arg->value)];
                }
            }

            return $names;
        }

        return [];
    }

    /** @psalm-external-mutation-free */
    private function captureScopeLocalFromAssign(Node\Expr\Assign $assign): void
    {
        $this->captureLhsTargets($assign->var);
    }

    /** @psalm-external-mutation-free */
    private function captureForeachLocals(Node\Stmt\Foreach_ $foreach): void
    {
        $this->captureLhsTargets($foreach->valueVar);

        if ($foreach->keyVar instanceof Node\Expr) {
            $this->captureLhsTargets($foreach->keyVar);
        }
    }

    /**
     * Walks an LHS expression (assignment target or foreach value/key var)
     * and records every {@see Node\Expr\Variable} it finds as a scope-local.
     *
     * Handles nested destructuring: `[$a, [$b, $c]] = $data` records `a`, `b`,
     * `c`; `@foreach ($pairs as [$k, $v])` records `k`, `v`. Without this
     * walk, downstream raw echoes of the destructured names would surface as
     * false-positive view-data keys.
     *
     * @psalm-external-mutation-free
     */
    private function captureLhsTargets(Node\Expr $target): void
    {
        if ($target instanceof Node\Expr\Variable && \is_string($target->name)) {
            $this->scopeLocals[$target->name] = true;
            return;
        }

        if ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
            foreach ($target->items as $item) {
                if (!($item instanceof Node\ArrayItem)) {
                    continue;
                }

                $this->captureLhsTargets($item->value);
            }
        }
    }

    /** @psalm-external-mutation-free */
    private function classifyEnvMethodCall(Node\Expr\MethodCall $call): void
    {
        if (!($call->var instanceof Node\Expr\Variable)) {
            return;
        }

        if ($call->var->name !== '__env') {
            return;
        }

        if (!($call->name instanceof Node\Identifier)) {
            return;
        }

        $method = $call->name->toString();

        if (\in_array($method, BladeTemplateScanner::envComponentMethods(), true)) {
            $this->addUncertainty(BladeUncertaintyReason::ComponentTag);
            return;
        }

        if (\in_array($method, BladeTemplateScanner::envLayoutMethods(), true)
            || \in_array($method, BladeTemplateScanner::envStackMethods(), true)
        ) {
            $this->addUncertainty(BladeUncertaintyReason::LayoutSectionFlow);
            return;
        }

        if (\in_array($method, BladeTemplateScanner::envIncludeMethods(), true)) {
            // Only `$__env->make(...)` (compiled from `@include` /
            // `@includeIf` / `@includeIsolated`) is statically resolvable
            // here. `first` / `renderEach` / `renderWhen` / `renderUnless`
            // come from include-family directives the scanner does not yet
            // model precisely (different call-arg shapes; resolution is
            // tracked in PR-5+), so they fall through to the conservative
            // IncludeDirective uncertainty unchanged.
            if ($method === 'make' && $this->tryRecordIncludeEdge($call)) {
                $this->addUncertainty(BladeUncertaintyReason::IncludeResolved);
                return;
            }

            $this->addUncertainty(BladeUncertaintyReason::IncludeDirective);
        }
    }

    /**
     * Inspect a compiled `$__env->make(...)` call. When the view-name argument
     * is a literal string AND (in the 3-arg form) the explicit-data argument
     * is a literal `Array_`, record a {@see BladeIncludeEdge} and return true.
     * Otherwise return false so the caller falls through to the unresolvable
     * `IncludeDirective` path.
     *
     * `compileInclude()` emits at most three arguments:
     *  - 2 args: `make($view, $mergeData = array_diff_key(get_defined_vars(),
     *    ['__data' => 1, '__path' => 1]))`. There is no explicit data array;
     *    every parent-scope variable flows through `mergeData`.
     *  - 3 args: `make($view, $explicitData, $mergeData = array_diff_key(...))`.
     *    The user wrote `@include('child', [...])` and the second argument is
     *    the literal data array (Laravel does not transform it).
     *
     * Any other call shape (zero args, four+ args, `$__env->make(...)` outside
     * the include compilation path) is treated as unresolvable.
     *
     * @psalm-external-mutation-free
     */
    private function tryRecordIncludeEdge(Node\Expr\MethodCall $call): bool
    {
        $args = $call->args;

        if (\count($args) < 2 || \count($args) > 3) {
            return false;
        }

        $viewArg = $args[0];

        if (!$viewArg instanceof Node\Arg || $viewArg->unpack) {
            return false;
        }

        if (!$viewArg->value instanceof Node\Scalar\String_) {
            return false;
        }

        $view = $viewArg->value->value;

        if ($view === '') {
            return false;
        }

        if (\count($args) === 2) {
            // 2-arg form: arg1 is the implicit mergeData (array_diff_key call).
            // No explicit user-provided data array; encode as `null` so the
            // propagation pass falls back entirely to the verbatim-key rule.
            $this->rawIncludeEdges[] = ['view' => $view, 'explicit' => null];

            return true;
        }

        $dataArg = $args[1];

        if (!$dataArg instanceof Node\Arg || $dataArg->unpack) {
            return false;
        }

        if (!$dataArg->value instanceof Node\Expr\Array_) {
            return false;
        }

        $mapping = $this->parseLiteralKeyMap($dataArg->value);

        if ($mapping === null) {
            return false;
        }

        $this->rawIncludeEdges[] = ['view' => $view, 'explicit' => $mapping];

        return true;
    }

    /**
     * Build a key-to-bound-vars map from a literal data-array node. Returns
     * null if any item carries an unenumerable key shape (argument spread,
     * dynamic key); the caller falls through to the unresolvable path.
     *
     * Integer keys, list-style entries, and invalid-identifier string keys are
     * silently skipped because `extract()` would not bind them to a usable
     * variable name in the child template — including them would create
     * keys that no child raw echo can match.
     *
     * @return array<non-empty-string, list<string>>|null
     *
     * @psalm-mutation-free
     */
    private function parseLiteralKeyMap(Node\Expr\Array_ $array): ?array
    {
        $map = [];

        foreach ($array->items as $item) {
            if (!$item instanceof Node\ArrayItem) {
                continue;
            }

            if ($item->unpack) {
                // Inline spread `[...$rest]` carries opaque keys.
                return null;
            }

            $keyNode = $item->key;

            if (!$keyNode instanceof \PhpParser\Node\Expr) {
                // List-style entry `['foo']`; extract() drops it.
                continue;
            }

            if ($keyNode instanceof Node\Scalar\Int_ || $keyNode instanceof Node\Scalar\LNumber) {
                // Integer literal key `[0 => $x]`; extract() drops it.
                // Two class names because php-parser renamed LNumber → Int_
                // in 5.x; matching both keeps the scanner forward-compatible.
                continue;
            }

            if (!$keyNode instanceof Node\Scalar\String_) {
                // Dynamic key shape: defeats enumeration.
                return null;
            }

            $keyName = $keyNode->value;

            if ($keyName === '' || \preg_match('/^[A-Za-z_]\w*$/', $keyName) !== 1) {
                // Non-identifier string key (`['1foo' => ...]`, empty string,
                // dotted etc.); extract() drops it for the same reason
                // BladeAwareViewTaintHandler::literalArrayKey() does.
                continue;
            }

            $map[$keyName] = $this->extractTopLevelVariables($item->value);
        }

        return $map;
    }

    /**
     * Project the raw edge list collected during traversal onto a list of
     * {@see BladeIncludeEdge} value objects, filtering each explicit-key
     * binding's variable list against scope-locals and framework-locals.
     *
     * Filtering happens here, not at edge-recording time, because the visitor
     * traverses top-down: an assignment inside an `@php ... @endphp` block
     * that follows an `@include` directive only registers as a scope-local
     * after the include itself has been visited.
     *
     * @param array<string, true> $frameworkLocals
     *
     * @return list<BladeIncludeEdge>
     *
     * @psalm-mutation-free
     */
    public function computeIncludeEdges(array $frameworkLocals): array
    {
        $edges = [];

        foreach ($this->rawIncludeEdges as $raw) {
            $rawExplicit = $raw['explicit'];

            if ($rawExplicit === null) {
                $edges[] = new BladeIncludeEdge($raw['view'], null);
                continue;
            }

            /** @var array<non-empty-string, list<non-empty-string>> $cleaned */
            $cleaned = [];

            foreach ($rawExplicit as $keyName => $names) {
                $filtered = [];

                foreach ($names as $name) {
                    if ($name === '') {
                        continue;
                    }

                    if (isset($this->scopeLocals[$name])) {
                        continue;
                    }

                    if (isset($frameworkLocals[$name])) {
                        continue;
                    }

                    $filtered[] = $name;
                }

                $cleaned[$keyName] = $filtered;
            }

            $edges[] = new BladeIncludeEdge($raw['view'], $cleaned);
        }

        return $edges;
    }

    /** @psalm-external-mutation-free */
    private function addUncertainty(BladeUncertaintyReason $reason): void
    {
        if (isset($this->seenUncertainty[$reason->name])) {
            return;
        }

        $this->seenUncertainty[$reason->name] = true;
        $this->uncertainties[] = $reason;
    }
}
