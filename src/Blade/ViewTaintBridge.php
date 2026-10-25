<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\View\ViewName;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Nop;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\LaravelPlugin\Handlers\Views\ViewNameSignatures;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Union;

/**
 * Carries taint from the data PHP code passes to `view()` into the template that receives it (#2031).
 *
 * A prelude variable is a docblock-typed script global with no data-flow node, so request input handed
 * to a template never reached its `{!! $q !!}`. Psalm 7's keyed taint stores bridge that: the PHP side
 * WRITES each tainted value under the view's name and the variable's name, and the template side READS
 * the same key at the prelude's sentinel. Store nodes carry no location, so a write recorded while one
 * worker analyses a controller and a read recorded while another analyses the shadow are the same node
 * once the parent merges the graphs.
 *
 * This class owns the store naming and every `Codebase` taint call, which keeps Psalm's pre-GA keyed
 * store API in one file.
 *
 * Source side: the `view()` helper, `View::make()`, `Factory::make()`, `response()->view()`, and the
 * data or `with()` entries they pass. Each link of a chain writes only its own arguments, which is
 * what lets a nested `response(view(...))` work. A literal array is written key by key. Any other
 * array (a variable, `compact()`) is written whole under the `*` name, and the sink reads it back
 * with `arrayvalue-fetch-'key'`, so Psalm's own array key paths keep the per-key precision. Skipped:
 * a dynamic view name, `View::share()`, composers, `Route::view()`, Livewire and mail.
 *
 * Inside a compiled template (#2032) the same calls cover the include directives, whose compiled
 * `$__env->make(...)` also hands over the includer's scope (`array_diff_key(get_defined_vars(), ...)`,
 * written as every variable in scope under its own name), and the anonymous component tag, whose
 * `AnonymousComponent::resolve(['view' => ..., 'data' => [...]])` data is written raw. The component's
 * `withAttributes()` copy goes through `e()`, so `{{ $attributes }}` stays clean and is never written.
 * A class component tag's data flows through the constructor into the public property nodes (#2033),
 * which the sink connects to the view(s) {@see ComponentViewRegistry} maps the class to. A property node
 * belongs to its class, not to a tag: one tainted `<x-alert>` taints `$message` in every render of it.
 *
 * Sink side: runs on the same sentinel as {@see ComponentViewSeedHandler}, and MUST be registered
 * after it, because the seed replaces a variable's whole type and would drop the nodes attached here.
 *
 * @internal
 */
final class ViewTaintBridge implements AfterExpressionAnalysisInterface, AfterStatementAnalysisInterface
{
    /** `with` and the binders' own names: nothing else can start a bridged call. */
    private const CALL_NAMES = ['view' => true, 'make' => true, 'with' => true];

    /** Names common elsewhere (`Collection::first()`), so they start a bridged call only inside a compiled template. */
    private const TEMPLATE_CALL_NAMES = ['first' => true, 'renderwhen' => true, 'renderunless' => true, 'resolve' => true];

    /** The `$__env` methods a compiled include directive calls. */
    private const INCLUDE_CALLS = ['make' => true, 'first' => true, 'renderwhen' => true, 'renderunless' => true];

    /**
     * Names view data never reaches the template under: `extract($__data, EXTR_SKIP)` keeps the engine's
     * own `$__path` and `$__data`, and `$__env` is the factory every compiled directive calls. Every other
     * name (`$errors`, `$slot`, `__q`) takes view data at run time, which `View::gatherData()` merges after
     * the shared values; a name the compiled code assigns before reading it loses the read node there.
     */
    private const NEVER_VIEW_DATA = ['__env' => true, '__path' => true, '__data' => true];

    private const COMPONENT = 'illuminate\view\component';

    private const ANONYMOUS_COMPONENT = 'illuminate\view\anonymouscomponent';

    /** The name a whole data array is stored under: a variable name is never `*`. */
    private const WHOLE_ARRAY = '*';

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        $name = self::callName($call);

        if ($name === null || !$call instanceof Expr\CallLike || (!isset(self::CALL_NAMES[$name]) && !isset(self::TEMPLATE_CALL_NAMES[$name]))) {
            return null;
        }

        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();

        if (!$codebase->tracksTaintFlow($source)) {
            return null;
        }

        $inTemplate = ShadowRegistry::entryFor($source->getFilePath()) instanceof ShadowEntry;

        if (!$inTemplate && isset(self::TEMPLATE_CALL_NAMES[$name])) {
            return null;
        }

        if ($call instanceof Expr\StaticCall && $name === 'resolve') {
            $class = self::receiverClass($call->class);

            if ($class === self::ANONYMOUS_COMPONENT) {
                self::bridgeAnonymousComponent($codebase, $source, $call);
            } elseif ($class !== null) {
                self::bridgeClassComponent($codebase, $source, $call, $class);
            }

            return null;
        }

        // A compiled include is a Factory call whose data is any shape, so it is resolved by its own rules.
        $includedViews = $inTemplate && $call instanceof Expr\MethodCall && isset(self::INCLUDE_CALLS[$name])
            ? ViewCallChain::includedViews($call, $source)
            : [];

        if ($includedViews !== [] && $call instanceof Expr\MethodCall) {
            if (self::isDisabled($call, $name, $source)) {
                return null;
            }

            $scope = self::passesScope($call, $name);

            foreach ($includedViews as $includedView) {
                self::bridgeCall($codebase, $source, $call, $includedView, ViewNameSignatures::ROLE_VIEW_FACTORY);

                if ($scope) {
                    self::bridgeScope($codebase, $source, $call, $includedView, $event->getContext());
                }
            }

            return null;
        }

        // Laravel's finder trims the name before it resolves it, so a padded literal still binds its template.
        $chain = ViewCallChain::from($call, $source, true);

        if ($chain instanceof ViewCallChain
            && \in_array($chain->binder, [null, ViewNameSignatures::ROLE_VIEW_FACTORY, ViewNameSignatures::ROLE_RESPONSE_FACTORY], true)
        ) {
            self::bridgeCall($codebase, $source, $call, $chain->viewName, $chain->binder);
        }

        return null;
    }

    /** What THIS call passes: literal keys one by one, any other array whole. */
    private static function bridgeCall(Codebase $codebase, StatementsSource $source, Expr\CallLike $call, string $view, ?string $binder): void
    {
        foreach (ViewCallChain::ownEntries($call, $binder) as [$key, $value]) {
            if (!self::isVariableName($key)) {
                continue;
            }

            self::write($codebase, $source, $view, $key, "\${$key} passed to view {$view}", $source->getNodeTypeProvider()->getType($value), $value);
        }

        foreach (ViewCallChain::ownArrays($call, $binder, $source) as $array) {
            self::write($codebase, $source, $view, self::WHOLE_ARRAY, "data passed to view {$view}", $source->getNodeTypeProvider()->getType($array), $array);
        }
    }

    /**
     * `@includeWhen`/`@includeUnless` render nothing, and run no include, when the condition is
     * decided: `renderWhen(false, ...)`, `renderUnless(true, ...)`.
     */
    private static function isDisabled(Expr\MethodCall $call, string $name, StatementsSource $source): bool
    {
        if ($name !== 'renderwhen' && $name !== 'renderunless') {
            return false;
        }

        $condition = ArgUtil::byNameOrPosition(\array_values($call->getArgs()), 0, 'condition');
        $type = $condition instanceof Arg ? $source->getNodeTypeProvider()->getType($condition->value) : null;

        if (!$type instanceof Union) {
            return false;
        }

        return $name === 'renderwhen' ? $type->isAlwaysFalsy() : $type->isAlwaysTruthy();
    }

    /**
     * A compiled `@include` hands its includer's whole scope over as `$data` (when the author passed
     * none) or `$mergeData`: `array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])`.
     * Psalm cannot follow it, so every variable in scope is written under its own name: a sound
     * over-approximation. Only that exact text counts, not a variable name or a filter an author wrote
     * (`array_diff_key(get_defined_vars(), ['q' => 1])`), and only where the compiler puts it, in the
     * two data positions after the view name.
     */
    private static function passesScope(Expr\MethodCall $call, string $name): bool
    {
        $args = \array_values($call->getArgs());
        $at = $name === 'renderwhen' || $name === 'renderunless' ? 1 : 0;

        foreach ([ArgUtil::byNameOrPosition($args, $at + 1, 'data'), ArgUtil::byNameOrPosition($args, $at + 2, 'mergedata')] as $arg) {
            if ($arg instanceof Arg && self::isCompiledScope($arg->value)) {
                return true;
            }
        }

        return false;
    }

    private static function isCompiledScope(Expr $expr): bool
    {
        if (!$expr instanceof Expr\FuncCall || !self::isFunction($expr, 'array_diff_key') || \count($expr->getArgs()) !== 2) {
            return false;
        }

        [$scope, $excluded] = $expr->getArgs();

        if (!$scope->value instanceof Expr\FuncCall || !self::isFunction($scope->value, 'get_defined_vars') || $scope->value->getArgs() !== []) {
            return false;
        }

        if (!$excluded->value instanceof Expr\Array_ || \count($excluded->value->items) !== 2) {
            return false;
        }

        $keys = [];

        foreach ($excluded->value->items as $item) {
            if ($item === null || $item->unpack || !$item->key instanceof String_) {
                return false;
            }

            $keys[] = $item->key->value;
        }

        \sort($keys);

        return $keys === ['__data', '__path'];
    }

    private static function isFunction(Expr\FuncCall $call, string $name): bool
    {
        return !$call->isFirstClassCallable() && $call->name instanceof Name && $call->name->toLowerString() === $name;
    }

    private static function bridgeScope(Codebase $codebase, StatementsSource $source, Expr\MethodCall $call, string $view, Context $context): void
    {
        foreach ($context->vars_in_scope as $variable => $type) {
            // The context also keys narrowed `$a['b']` and `$a->b`; only a plain name can be a view variable.
            if (\preg_match('/^\$(\w+)$/', $variable, $match) !== 1 || isset(self::NEVER_VIEW_DATA[$match[1]])) {
                continue;
            }

            self::write($codebase, $source, $view, $match[1], "\${$match[1]} inherited by view {$view}", $type, $call);
        }
    }

    /**
     * `AnonymousComponent::resolve(['view' => 'components.x', 'data' => [...]] + $outerAttributes)`: the
     * `data` values are the component view's variables and reach it raw. The tag's `withAttributes()`
     * copy is sanitised by `e()` and is not written.
     */
    private static function bridgeAnonymousComponent(Codebase $codebase, StatementsSource $source, Expr\StaticCall $call): void
    {
        $array = self::tagData($call);
        $view = null;
        $data = null;

        foreach ($array instanceof Expr\Array_ ? ViewCallChain::arrayEntries($array) : [] as [$key, $value]) {
            $view = $key === 'view' && $value instanceof String_ ? \trim(ViewName::normalize($value->value)) : $view;
            $data = $key === 'data' && $value instanceof Expr\Array_ ? $value : $data;
        }

        if ($view === null || $view === '' || !$data instanceof Expr\Array_) {
            return;
        }

        foreach (ViewCallChain::arrayEntries($data) as [$key, $value]) {
            if (!self::isVariableName($key)) {
                continue;
            }

            self::write($codebase, $source, $view, $key, "\${$key} passed to view {$view}", $source->getNodeTypeProvider()->getType($value), $value);
        }
    }

    /**
     * `Foo::resolve([...] + $outerAttributes)`, what `<x-foo>` and `@component(Foo::class)` compile to:
     * `new static(...array_intersect_key($data, ...))` binds each key to the constructor PARAMETER of that
     * name. So each entry flows into the argument node `new Foo($x)` uses, and on through the constructor
     * body: `e($message)` stays clean, a parameter stored under another name still reaches it. The view
     * reads the resulting property nodes at the sentinel.
     */
    private static function bridgeClassComponent(Codebase $codebase, StatementsSource $source, Expr\StaticCall $call, string $className): void
    {
        try {
            $class = $codebase->classlike_storage_provider->get($className);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return;
        }

        // A look-alike's own `resolve()` hands its data to code this bridge knows nothing about.
        $constructor = isset($class->parent_classes[self::COMPONENT]) ? $class->declaring_method_ids['__construct'] ?? null : null;
        $array = self::tagData($call);

        if (!$constructor instanceof MethodIdentifier || !$array instanceof Expr\Array_ || !$codebase->methods->hasStorage($constructor)) {
            return;
        }

        // A trait constructor's body is analyzed as the using class's own, so its parameter nodes are keyed by
        // that class, not by the trait the storage comes from. No public Psalm API returns this id (#1978).
        // The assignment node behind it is still shared by every user of the trait: upstream Psalm, documented.
        $casedId = FunctionLikeAnalyzer::getCasedBodyMethodId($codebase, new MethodIdentifier($class->name, '__construct'));
        $storage = $codebase->methods->getStorage($constructor);
        $offsets = [];

        foreach ($storage->params as $offset => $param) {
            if (!$param->is_variadic) {
                $offsets[$param->name] = $offset;
            }
        }

        foreach (ViewCallChain::arrayEntries($array) as [$key, $value]) {
            $type = $source->getNodeTypeProvider()->getType($value);

            if (!isset($offsets[$key]) || !$type instanceof Union || $type->parent_nodes === []) {
                continue;
            }

            $node = $codebase->getTaintNode($source, "\${$key} of component {$class->name}", new CodeLocation($source, $value), $type);
            $codebase->addTaintPath($source, $node, DataFlowNode::getForMethodArgument($casedId, $offsets[$key], $storage), 'arg');
        }
    }

    /** The tag's own data array: the left operand of the compiled `[...] + $attributes`. */
    private static function tagData(Expr\StaticCall $call): ?Expr
    {
        $arg = $call->isFirstClassCallable() ? null : ($call->getArgs()[0] ?? null);
        $array = $arg === null || $arg->unpack ? null : $arg->value;

        return $array instanceof Expr\BinaryOp\Plus ? $array->left : $array;
    }

    /**
     * The lower-cased class a `Foo::resolve()` or `Foo::class::resolve()` receiver names.
     *
     * @return lowercase-string|null
     */
    private static function receiverClass(Expr|Name $class): ?string
    {
        if ($class instanceof Expr\ClassConstFetch && $class->name instanceof Identifier && $class->name->toLowerString() === 'class') {
            $class = $class->class;
        }

        if (!$class instanceof Name) {
            return null;
        }

        /** @psalm-var ?string $resolved */
        $resolved = $class->getAttribute('resolvedName');

        return \strtolower(\ltrim(\is_string($resolved) ? $resolved : $class->toString(), '\\'));
    }

    /**
     * `extract()` skips a key that is not a variable name, so such a literal key is never view data
     * (and `*` would collide with the store name of a whole array).
     *
     * @psalm-pure
     */
    private static function isVariableName(string $key): bool
    {
        return \preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $key) === 1;
    }

    /** One write of a tainted value into the store of `$view`, under `$key`. A value that carries no taint writes nothing. */
    private static function write(Codebase $codebase, StatementsSource $source, string $view, string $key, string $label, ?Union $type, Expr $at): void
    {
        if (!$type instanceof Union || $type->parent_nodes === []) {
            return;
        }

        $node = $codebase->getTaintNode($source, $label, new CodeLocation($source, $at));
        $codebase->addTaintStoreWrite($source, self::store($view), [[true, $key]], $node, '=', $type);
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();

        if (!$stmt instanceof Nop) {
            return null;
        }

        $source = $event->getStatementsSource();
        $entry = ShadowRegistry::entryFor($source->getFilePath());
        $codebase = $event->getCodebase();

        // The prelude is the only code on a line the line map sends to template line 0.
        if (!$entry instanceof ShadowEntry || ($entry->lineMap[$stmt->getStartLine()] ?? null) !== 0 || !$codebase->tracksTaintFlow($source)) {
            return null;
        }

        $views = ViewReferenceRegistry::namesFor($entry->templatePath);
        $properties = ComponentViewRegistry::propertyIdsFor($source->getFilePath());

        if ($views === [] && $properties === []) {
            return null;
        }

        $context = $event->getContext();
        $location = new CodeLocation($source, $stmt);

        foreach ($context->vars_in_scope as $variable => $type) {
            $name = \substr($variable, 1);

            if (isset(self::NEVER_VIEW_DATA[$name])) {
                continue;
            }

            $reads = [];

            // A class component's view: the public property nodes of every class rendering it.
            if (isset($properties[$name])) {
                $node = $codebase->getTaintNode($source, "\${$name} (component property)", $location);

                foreach ($properties[$name] as $id) {
                    $codebase->addTaintPath($source, Codebase::getTaintPropertyNode($id), $node);
                }

                $reads[$node->id] = $node;
            }

            foreach ($views as $view) {
                $node = $codebase->getTaintNode($source, "\${$name} (view data of {$view})", $location);
                $codebase->addTaintStoreRead($source, self::store($view), [[true, $name]], $node, '=');
                // A whole array written under `*` keeps its per-key paths: only this key leaves it.
                $codebase->addTaintStoreRead($source, self::store($view), [[true, self::WHOLE_ARRAY]], $node, "arrayvalue-fetch-'{$name}'");
                $reads[$node->id] = $node;
            }

            $context->vars_in_scope[$variable] = $type->addParentNodes($reads);
        }

        return null;
    }

    /**
     * One store per canonical view name; the keys are variable names.
     *
     * @psalm-pure
     */
    private static function store(string $viewName): string
    {
        return 'Illuminate\View\View::$data@' . $viewName;
    }

    /** The cheapest gate: the lower-cased name of a function, method or static call, whatever it is called on. */
    private static function callName(Expr $expr): ?string
    {
        $name = match (true) {
            $expr instanceof Expr\FuncCall => $expr->name instanceof Name ? $expr->name : null,
            $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall, $expr instanceof Expr\StaticCall => $expr->name instanceof Identifier ? $expr->name : null,
            default => null,
        };

        return $name?->toLowerString();
    }
}
