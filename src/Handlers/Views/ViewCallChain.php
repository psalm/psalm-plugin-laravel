<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * The view name and the full data set of one view-rendering expression, recovered by walking the
 * call chain from the OUTERMOST call inwards.
 *
 * Outermost-first is what makes `view('greeting')->with('name', $n)` answerable at all: the inner
 * `view('greeting')` supplies no data, so a check that fired there would report every declared
 * variable as missing. Only the outermost call sees every `with()` contribution.
 *
 * The receiver-shape table and the refuse-on-ambiguity rules are salvaged from the closed scanner
 * branch's `ReceiverViewNameResolver`, extended to carry the data contributions alongside the name
 * so both come out of one walk and cannot disagree.
 *
 * Shapes it resolves:
 *  - `view('home', [...])`, and `view('home')->with(...)` chains
 *  - `View::make('home', [...])` / `$factory->make('home', [...])`
 *  - `response()->view('home', [...])`
 *  - `$mailable->view('home', [...])` / `->markdown(...)`, and `MailMessage`'s equivalents
 *
 * It refuses (returns null) on anything else, including a chain carrying an unrecognized method
 * call: an unknown link may bind a different template or inject data, and a wrong answer here is a
 * false positive at the call site.
 *
 * The ARGUMENTS are not the whole data set, though: a chain inside an `Illuminate\View\Component`
 * subclass also receives whatever `Component::data()` merges in, which {@see ComponentRenderData}
 * resolves from the analyzed class and which lands in `$frameworkData` rather than `$data`.
 *
 * @internal
 */
final class ViewCallChain
{
    /**
     * @param array<string, Union> $data          supplied variable name => the type passed for it
     * @param array<string, Union> $frameworkData keys Laravel merges in around the call site rather
     *                                            than from its arguments, so they count as supplied
     *                                            but were never PASSED — {@see ComponentRenderData}
     * @param bool                 $complete      every contribution was proven, so a name absent
     *                                            from both sets is provably not supplied
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public readonly string $viewName,
        public readonly array $data,
        public readonly bool $complete,
        public readonly array $frameworkData = [],
    ) {}

    /**
     * Every key the rendered template actually receives, whatever supplied it. Framework data wins
     * a shared key: `ManagesComponents::renderComponent()` ends in `$view->with($componentData)`,
     * which merges after whatever the call site passed.
     *
     * @return array<string, Union>
     *
     * @psalm-mutation-free
     */
    public function supplied(): array
    {
        return $this->frameworkData + $this->data;
    }

    public static function from(Expr $expr, StatementsSource $source): ?self
    {
        $chain = self::walk($expr, $source);

        if (!$chain instanceof self) {
            return null;
        }

        $merged = ComponentRenderData::forSource($source);

        if ($merged === null) {
            return $chain;
        }

        // A component's public `render()` can be called, and its result chained, from anywhere
        // (`$component->render()->with([...])`): the data added there is not in this chain.
        $complete = $chain->complete && $merged[1] && !self::isInRenderMethod($source);

        return new self($chain->viewName, $chain->data, $complete, $merged[0]);
    }

    /** @psalm-mutation-free */
    private static function isInRenderMethod(StatementsSource $source): bool
    {
        $analyzer = $source instanceof StatementsAnalyzer ? $source->getSource() : null;

        return $analyzer instanceof FunctionLikeAnalyzer && \strtolower((string) $analyzer->getMethodName()) === 'render';
    }

    private static function walk(Expr $expr, StatementsSource $source): ?self
    {
        /** @var list<array{0: 'with'|'witherrors', 1: list<Arg>}> $pending */
        $pending = [];
        $node = $expr;

        // Terminates on the expression's own nesting: every branch either returns or steps one link
        // closer to the receiver.
        while (true) {
            if ($node instanceof Expr\FuncCall) {
                return self::fromHelperCall($node, $source, $pending);
            }

            if (!$node instanceof Expr\MethodCall
                && !$node instanceof Expr\NullsafeMethodCall
                && !$node instanceof Expr\StaticCall
            ) {
                return null;
            }

            if (!$node->name instanceof Identifier) {
                return null;
            }

            $args = self::args($node);

            if ($args === null) {
                return null;
            }

            $methodName = $node->name->toLowerString();

            if ($node instanceof Expr\StaticCall) {
                return self::fromBinder(self::roleOfStaticClass($node->class), $methodName, $args, $source, $pending);
            }

            // Collected rather than folded in here: whether these carry template data at all depends
            // on the receiver the walk has not reached yet.
            if ($methodName === 'with' || $methodName === 'witherrors') {
                $pending[] = [$methodName, $args];
                $node = $node->var;

                continue;
            }

            return self::fromBinder(self::roleOfReceiver($node->var, $source), $methodName, $args, $source, $pending);
        }
    }

    /**
     * @param list<array{0: 'with'|'witherrors', 1: list<Arg>}> $pending
     */
    private static function fromHelperCall(Expr\FuncCall $call, StatementsSource $source, array $pending): ?self
    {
        if (!$call->name instanceof Name || $call->name->toLowerString() !== 'view') {
            return null;
        }

        $args = self::args($call);

        if ($args === null) {
            return null;
        }

        // view($view = null, $data = [], $mergeData = []): $mergeData lands in the same data array,
        // so anything supplied there that we cannot read leaves the key set open.
        $complete = !ArgUtil::byNameOrPosition($args, 2, 'mergedata') instanceof Arg;

        return self::build(self::viewArg($args), ArgUtil::byNameOrPosition($args, 1, 'data'), $source, $pending, true, $complete);
    }

    /**
     * @param list<Arg>                                        $args
     * @param list<array{0: 'with'|'witherrors', 1: list<Arg>}> $pending
     */
    private static function fromBinder(
        ?string $role,
        string $methodName,
        array $args,
        StatementsSource $source,
        array $pending,
    ): ?self {
        $binds = match ($role) {
            ViewNameSignatures::ROLE_VIEW_FACTORY => $methodName === 'make',
            ViewNameSignatures::ROLE_RESPONSE_FACTORY => $methodName === 'view',
            ViewNameSignatures::ROLE_MAILABLE, ViewNameSignatures::ROLE_MAIL_MESSAGE => $methodName === 'view' || $methodName === 'markdown',
            default => false,
        };

        if (!$binds) {
            return null;
        }

        $complete = $role !== ViewNameSignatures::ROLE_VIEW_FACTORY || !ArgUtil::byNameOrPosition($args, 2, 'mergedata') instanceof Arg;

        // `SimpleMessage::with($line)`, which is what a MailMessage chain's `with()` reaches, appends
        // an intro/outro line to the notification body and never touches the template's data.
        $carriesData = $role !== ViewNameSignatures::ROLE_MAIL_MESSAGE;

        return self::build(self::viewArg($args), ArgUtil::byNameOrPosition($args, 1, 'data'), $source, $pending, $carriesData, $complete);
    }

    /**
     * The `$__env` Factory call a compiled include reduces to, or null for any other call:
     * `make($view, $data, $mergeData)` (`@include`, `@includeIf`, `@extends`, `@includeIsolated`),
     * `renderWhen`/`renderUnless($condition, $view, $data, $mergeData)`, and
     * `first($views, $data, $mergeData)` (`@includeFirst`, `@extendsFirst`).
     *
     * Only a literal data array is read: without one, Blade puts the including scope in the `$data`
     * slot. The exception is `@includeIsolated('view')`, whose lone-name `make('view')` passes no
     * data at all.
     *
     * `first()` is checked against the first candidate that exists, which is the one
     * `Factory::first()` renders. Existence is read from disk at analysis time, so its key set is
     * never treated as closed: only the passed keys are type-checked.
     */
    public static function fromInclude(Expr\MethodCall $call, StatementsSource $source): ?self
    {
        $args = self::args($call);

        if ($args === null
            || !$call->name instanceof Identifier
            || self::roleOfReceiver($call->var, $source) !== ViewNameSignatures::ROLE_VIEW_FACTORY
        ) {
            return null;
        }

        $methodName = $call->name->toLowerString();

        [$name, $at] = match ($methodName) {
            'make' => [self::viewArg($args), 0],
            'renderwhen', 'renderunless' => [ArgUtil::byNameOrPosition($args, 1, 'view')?->value, 1],
            'first' => [self::firstExistingCandidate(ArgUtil::byNameOrPosition($args, 0, 'views')), 0],
            default => [null, 0],
        };

        $dataArg = ArgUtil::byNameOrPosition($args, $at + 1, 'data');
        $literalData = $dataArg instanceof Arg && $dataArg->value instanceof Expr\Array_;
        $isolatedWithoutData = !$dataArg instanceof Arg && $methodName === 'make' && \count($args) === 1;

        if (!$literalData && !$isolatedWithoutData) {
            return null;
        }

        $complete = $methodName !== 'first' && !ArgUtil::byNameOrPosition($args, $at + 2, 'mergedata') instanceof Arg;

        return self::build($name, $dataArg, $source, [], true, $complete);
    }

    /**
     * @param list<Arg> $args
     */
    private static function viewArg(array $args): ?Expr
    {
        return ArgUtil::byNameOrPosition($args, 0, 'view')?->value;
    }

    /**
     * The first candidate of a literal list that exists on disk, walking past the ones provably
     * absent, as `Arr::first()` does. The whole list must be plain string literals first: a key
     * anywhere can overwrite an earlier element, and `FileViewFinder::find()` trims a name, so
     * `' inc.card '` may render `inc.card` while this lookup misses it. A candidate of unknowable
     * existence (namespaced) before the first one found declines too.
     */
    private static function firstExistingCandidate(?Arg $views): ?String_
    {
        if (!$views instanceof Arg || !$views->value instanceof Expr\Array_) {
            return null;
        }

        $candidates = [];

        foreach ($views->value->items as $item) {
            if ($item === null
                || $item->key instanceof Expr
                || !$item->value instanceof String_
                || $item->value->value !== \trim($item->value->value)
            ) {
                return null;
            }

            $candidates[] = $item->value;
        }

        foreach ($candidates as $candidate) {
            $exists = MissingViewHandler::viewExists($candidate->value);

            if ($exists !== false) {
                return $exists === true ? $candidate : null;
            }
        }

        return null;
    }

    /**
     * Every binder this class recognizes takes `($view, $data)` in that order. `Router::view()` is
     * the exception that puts the name at position 1, which is why it is not in the table above.
     *
     * @param list<array{0: 'with'|'witherrors', 1: list<Arg>}> $pending
     */
    private static function build(
        ?Expr $name,
        ?Arg $dataArg,
        StatementsSource $source,
        array $pending,
        bool $carriesData,
        bool $complete,
    ): ?self {
        if (!$name instanceof String_) {
            return null;
        }

        $viewName = $name->value;

        // A namespaced name resolves through package-registered roots the compile pass does not
        // own, so its contract is never in the registry and guessing would be wrong.
        if ($viewName === '' || \str_contains($viewName, '::')) {
            return null;
        }

        /** @var array<string, Union> $data */
        $data = [];

        // The chain's contributions land before the binder's own data argument, because the walk ran
        // outermost-first and `??=` below lets the first contribution seen — the last one to run — win.
        if ($carriesData && !self::mergePending($pending, $source, $data, $complete)) {
            return null;
        }

        if ($dataArg instanceof Arg) {
            self::mergeArrayArg($dataArg, $source, $data, $complete);
        }

        return new self($viewName, $data, $complete);
    }

    /**
     * @param list<array{0: 'with'|'witherrors', 1: list<Arg>}> $pending
     * @param array<string, Union>                             $data
     */
    private static function mergePending(array $pending, StatementsSource $source, array &$data, bool &$complete): bool
    {
        foreach ($pending as [$methodName, $args]) {
            if ($methodName === 'witherrors') {
                // withErrors() forwards to with('errors', ...) — the key is fixed, and the value is
                // a ViewErrorBag no template declares a type for, so mixed carries enough.
                $data['errors'] ??= Type::getMixed();

                continue;
            }

            if (!self::mergeWith($args, $source, $data, $complete)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `with()` dispatches on `is_array($key)`, never on the argument count: `with('name')` really
     * does assign null to 'name', and `with(['name' => $n], $ignored)` still merges the array.
     *
     * @param list<Arg>            $args
     * @param array<string, Union> $data
     */
    private static function mergeWith(array $args, StatementsSource $source, array &$data, bool &$complete): bool
    {
        $keyArg = ArgUtil::byNameOrPosition($args, 0, 'key');

        if (!$keyArg instanceof Arg) {
            return false;
        }

        $merges = self::mergesArray($keyArg, $source);

        if ($merges === true) {
            self::mergeArrayArg($keyArg, $source, $data, $complete);

            return true;
        }

        if ($merges === null || !$keyArg->value instanceof String_) {
            // Either branch could run, or the key names a variable we cannot read: nothing the
            // template declares is provably absent, and nothing is provably present either.
            $complete = false;

            return true;
        }

        $valueArg = ArgUtil::byNameOrPosition($args, 1, 'value');

        // Walking outermost-first means the first contribution seen is the last one to run, and
        // Laravel lets the last write win.
        $data[$keyArg->value->value] ??= $valueArg instanceof Arg
            ? $source->getNodeTypeProvider()->getType($valueArg->value) ?? Type::getMixed()
            : Type::getNull();

        return true;
    }

    /**
     * Which branch of `with()` its key argument takes, or null when the inferred type admits both.
     */
    private static function mergesArray(Arg $keyArg, StatementsSource $source): ?bool
    {
        if ($keyArg->value instanceof String_) {
            return false;
        }

        $type = $source->getNodeTypeProvider()->getType($keyArg->value);

        if (!$type instanceof Union || $type->hasMixed()) {
            return null;
        }

        $atomics = $type->getAtomicTypes();
        $arrays = 0;

        foreach ($atomics as $atomic) {
            if ($atomic instanceof TArray || $atomic instanceof TKeyedArray) {
                ++$arrays;
            }
        }

        if ($arrays === 0) {
            return false;
        }

        return $arrays === \count($atomics) ? true : null;
    }

    /**
     * Fold one whole-array data argument into the supplied set. Only a single sealed keyed array
     * proves which names are present; any other shape leaves the set open rather than declining, so
     * keys contributed elsewhere in the chain still get type-checked.
     *
     * @param array<string, Union> $data
     */
    private static function mergeArrayArg(Arg $arg, StatementsSource $source, array &$data, bool &$complete): void
    {
        if ($arg->unpack) {
            $complete = false;

            return;
        }

        $atomic = self::soleAtomic($arg->value, $source);

        if (!$atomic instanceof Atomic) {
            $complete = false;

            return;
        }

        // `[]` infers as an empty TArray rather than a keyed array, and it is the one array shape
        // that proves the data set is empty.
        if ($atomic instanceof TArray && $atomic->isEmptyArray()) {
            return;
        }

        if (!$atomic instanceof TKeyedArray) {
            $complete = false;

            return;
        }

        if ($atomic->fallback_params !== null) {
            // A spread, or an inferred fallback, leaves room for keys we cannot see.
            $complete = false;
        }

        foreach ($atomic->properties as $key => $valueType) {
            if (!\is_string($key)) {
                continue;
            }

            if ($valueType->possibly_undefined) {
                $complete = false;

                continue;
            }

            $data[$key] ??= $valueType;
        }
    }

    /**
     * The one atomic an expression infers to, or null when the type is unknown or a union —
     * a union could be any of its arms, and picking one would be a guess.
     */
    private static function soleAtomic(Expr $expr, StatementsSource $source): ?Atomic
    {
        $type = $source->getNodeTypeProvider()->getType($expr);

        if (!$type instanceof Union) {
            return null;
        }

        $atomics = $type->getAtomicTypes();

        return \count($atomics) === 1 ? \reset($atomics) : null;
    }

    /**
     * The role of a receiver expression, following its class hierarchy: a `Mailable` at a call site
     * is almost always a userland subclass, and {@see ViewNameSignatures} keys on exact names.
     */
    private static function roleOfReceiver(Expr $receiver, StatementsSource $source): ?string
    {
        $atomic = self::soleAtomic($receiver, $source);

        if (!$atomic instanceof TNamedObject) {
            return null;
        }

        $role = ViewNameSignatures::resolveRole($atomic->value);

        if ($role !== null) {
            return $role;
        }

        try {
            $storage = $source->getCodebase()->classlike_storage_provider->get(\strtolower($atomic->value));
        } catch (\InvalidArgumentException|\Psalm\Exception\UnpopulatedClasslikeException) {
            return null;
        }

        foreach ([...\array_keys($storage->parent_classes), ...\array_keys($storage->class_implements)] as $ancestor) {
            $role = ViewNameSignatures::resolveRole($ancestor);

            if ($role !== null) {
                return $role;
            }
        }

        return null;
    }

    private static function roleOfStaticClass(Name|Expr $class): ?string
    {
        if (!$class instanceof Name) {
            return null;
        }

        /** @psalm-var ?string $resolvedName */
        $resolvedName = $class->getAttribute('resolvedName');

        return ViewNameSignatures::resolveRole($resolvedName ?? $class->toString());
    }

    /**
     * The call's arguments, or null when their positions cannot be trusted: a first-class callable
     * has none, and a spread shifts every position after it.
     *
     * @return list<Arg>|null
     */
    private static function args(Expr\CallLike $call): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $args = $call->getArgs();

        foreach ($args as $arg) {
            if ($arg->unpack) {
                return null;
            }
        }

        return \array_values($args);
    }
}
