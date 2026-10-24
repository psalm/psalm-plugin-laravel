<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\View\ViewName;
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
 * resolves from the analyzed class and which lands in `$frameworkData` rather than `$data`. So do
 * the keys a Mailable, a MailMessage and a Livewire `render()` receive: {@see FrameworkViewData}.
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
     * @param string|null          $binder        the {@see ViewNameSignatures} role of the receiver
     *                                            that bound the view name; null for the `view()` helper
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public readonly string $viewName,
        public readonly array $data,
        public readonly bool $complete,
        public readonly array $frameworkData = [],
        public readonly ?string $binder = null,
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

    public static function from(Expr $expr, StatementsSource $source, bool $trimName = false): ?self
    {
        $chain = self::walk($expr, $source, $trimName);

        if (!$chain instanceof self) {
            return null;
        }

        $merged = ComponentRenderData::forSource($source);

        if ($merged === null) {
            // Livewire merges the component's properties into the view its `render()` returns.
            return $chain->supplying(self::isInRenderMethod($source) ? FrameworkViewData::forLivewire($source) : null);
        }

        // A component's public `render()` can be called, and its result chained, from anywhere
        // (`$component->render()->with([...])`): the data added there is not in this chain.
        $merged[1] = $merged[1] && !self::isInRenderMethod($source);

        return $chain->supplying($merged);
    }

    /**
     * @param array{0: array<string, Union>, 1: bool}|null $supplied framework keys, and whether they
     *        are all of them; they win a name the chain already carries as framework data
     *
     * @psalm-capabilities read-props
     */
    private function supplying(?array $supplied): self
    {
        if ($supplied === null) {
            return $this;
        }

        return new self($this->viewName, $this->data, $this->complete && $supplied[1], $supplied[0] + $this->frameworkData, $this->binder);
    }

    /**
     * The `key => value expression` pairs THIS call passes itself, never the rest of its chain: the
     * literal `$data` (and, for the helper and a view factory, `$mergeData`) array of a binder, or the
     * key and value of a `with()` link. Whatever is not written out literally (a variable array, a
     * computed key, a spread of anything but an array literal) is skipped.
     *
     * Within the arrays the last write of a key wins, the way PHP builds the array, and `$data` wins
     * `$mergeData` as `Factory::make()` merges them. A non-literal spread or key after an entry keeps
     * it: it may or may not overwrite, and a taint write must never be dropped on a guess.
     *
     * Callers resolve the chain with {@see self::from()} first: this reads positions only, so it says
     * nothing about whether the call binds a view at all. `$binder` is that chain's role.
     *
     * @return list<array{0: string, 1: Expr}>
     */
    public static function ownEntries(Expr\CallLike $call, ?string $binder): array
    {
        $args = self::args($call);

        if ($args === null) {
            return [];
        }

        if (!$call instanceof Expr\MethodCall && !$call instanceof Expr\NullsafeMethodCall && !$call instanceof Expr\StaticCall
            || !$call->name instanceof Identifier
            || $call->name->toLowerString() !== 'with'
        ) {
            // `response()->view($view, $data, $status, $headers)` has no `$mergeData`: position 2 is the status.
            $mergeData = $call instanceof Expr\FuncCall || $binder === ViewNameSignatures::ROLE_VIEW_FACTORY
                ? ArgUtil::byNameOrPosition($args, 2, 'mergedata')
                : null;

            return self::literalEntries([$mergeData, ArgUtil::byNameOrPosition($args, 1, 'data')]);
        }

        $keyArg = ArgUtil::byNameOrPosition($args, 0, 'key');

        if (!$keyArg instanceof Arg) {
            return [];
        }

        if ($keyArg->value instanceof Expr\Array_) {
            return self::literalEntries([$keyArg]);
        }

        $valueArg = ArgUtil::byNameOrPosition($args, 1, 'value');

        return $keyArg->value instanceof String_ && $valueArg instanceof Arg ? [[$keyArg->value->value, $valueArg->value]] : [];
    }

    /**
     * @param list<Arg|null> $arrays in the order PHP merges them: a later array wins a shared key
     * @return list<array{0: string, 1: Expr}>
     *
     * @psalm-capabilities read-props
     */
    private static function literalEntries(array $arrays): array
    {
        $latest = [];

        foreach ($arrays as $arg) {
            if ($arg instanceof Arg && $arg->value instanceof Expr\Array_) {
                $latest = self::collectLiteralKeys($arg->value, $latest);
            }
        }

        $entries = [];

        foreach ($latest as $key => $value) {
            // A numeric string key reads back as an int.
            $entries[] = [(string) $key, $value];
        }

        return $entries;
    }

    /**
     * @param array<array-key, Expr> $latest
     * @return array<array-key, Expr>
     *
     * @psalm-capabilities read-props
     */
    private static function collectLiteralKeys(Expr\Array_ $array, array $latest): array
    {
        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->unpack) {
                if ($item->value instanceof Expr\Array_) {
                    $latest = self::collectLiteralKeys($item->value, $latest);
                }

                continue;
            }

            if ($item->key instanceof String_) {
                $latest[$item->key->value] = $item->value;
            }
        }

        return $latest;
    }

    /**
     * @psalm-pure
     */
    private static function isInRenderMethod(StatementsSource $source): bool
    {
        $analyzer = $source instanceof StatementsAnalyzer ? $source->getSource() : null;

        return $analyzer instanceof FunctionLikeAnalyzer && \strtolower((string) $analyzer->getMethodName()) === 'render';
    }

    private static function walk(Expr $expr, StatementsSource $source, bool $trimName): ?self
    {
        /** @var list<array{0: 'with'|'witherrors', 1: list<Arg>}> $pending */
        $pending = [];
        $node = $expr;

        // Terminates on the expression's own nesting: every branch either returns or steps one link
        // closer to the receiver.
        while (true) {
            if ($node instanceof Expr\FuncCall) {
                return self::fromHelperCall($node, $source, $pending, $trimName);
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
                return self::fromBinder(self::roleOfStaticClass($node->class), null, $methodName, $args, $source, $pending, $trimName);
            }

            // Collected rather than folded in here: whether these carry template data at all depends
            // on the receiver the walk has not reached yet.
            if ($methodName === 'with' || $methodName === 'witherrors') {
                $pending[] = [$methodName, $args];
                $node = $node->var;

                continue;
            }

            return self::fromBinder(self::roleOfReceiver($node->var, $source, $methodName), self::receiverClass($node->var, $source), $methodName, $args, $source, $pending, $trimName);
        }
    }

    /**
     * @param list<array{0: 'with'|'witherrors', 1: list<Arg>}> $pending
     */
    private static function fromHelperCall(Expr\FuncCall $call, StatementsSource $source, array $pending, bool $trimName): ?self
    {
        if (!self::isLaravelViewHelper($call, $source)) {
            return null;
        }

        $args = self::args($call);

        if ($args === null) {
            return null;
        }

        // view($view = null, $data = [], $mergeData = []): $mergeData lands in the same data array,
        // so anything supplied there that we cannot read leaves the key set open.
        $complete = !ArgUtil::byNameOrPosition($args, 2, 'mergedata') instanceof Arg;

        return self::build(self::viewArg($args), ArgUtil::byNameOrPosition($args, 1, 'data'), $source, $pending, true, $complete, null, $trimName);
    }

    /**
     * Whether a `view(...)` call reaches Laravel's global helper the way PHP resolves it: a `use function`
     * import or a same-named function in the current namespace wins over the global fallback, and
     * Psalm may not have seen a project function declared in a file this one never requires.
     */
    private static function isLaravelViewHelper(Expr\FuncCall $call, StatementsSource $source): bool
    {
        $name = $call->name;

        if (!$name instanceof Name || $name->toLowerString() !== 'view') {
            return false;
        }

        if ($name->isFullyQualified()) {
            return true;
        }

        if (!$name->isUnqualified() || isset($source->getAliases()->functions['view'])) {
            return false;
        }

        $namespace = $source->getNamespace();

        if ($namespace === null || $namespace === '') {
            return true;
        }

        return $source instanceof StatementsAnalyzer
            && !$source->getCodebase()->functions->functionExists($source, \strtolower($namespace . '\\view'));
    }

    /**
     * @param list<Arg>                                        $args
     * @param list<array{0: 'with'|'witherrors', 1: list<Arg>}> $pending
     */
    private static function fromBinder(
        ?string $role,
        ?string $receiverClass,
        string $methodName,
        array $args,
        StatementsSource $source,
        array $pending,
        bool $trimName,
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
        $view = self::viewArg($args);

        // `Markdown::render()`/`renderText()` swap the `mail` namespace for the markdown component
        // paths before rendering, so the boot-time `mail` hint the registry knows never renders here.
        if ($methodName === 'markdown' && $view instanceof String_ && \str_starts_with($view->value, 'mail::')) {
            return null;
        }

        $chain = self::build($view, ArgUtil::byNameOrPosition($args, 1, 'data'), $source, $pending, $carriesData, $complete, $role, $trimName);

        return match ($role) {
            ViewNameSignatures::ROLE_MAILABLE => $chain?->supplying(FrameworkViewData::forMailable($receiverClass, $chain->data, $source)),
            ViewNameSignatures::ROLE_MAIL_MESSAGE => $chain?->supplying(
                FrameworkViewData::forMailMessage($receiverClass, $chain->data, $chain->complete, $source),
            ),
            default => $chain,
        };
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

        if ($args === null || !$call->name instanceof Identifier) {
            return null;
        }

        $methodName = $call->name->toLowerString();

        // Resolved only now: the receiver's role depends on which method is called on it.
        if (self::roleOfReceiver($call->var, $source, $methodName) !== ViewNameSignatures::ROLE_VIEW_FACTORY) {
            return null;
        }

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
        ?string $binder = null,
        bool $trimName = false,
    ): ?self {
        if (!$name instanceof String_) {
            return null;
        }

        // `Factory::make()` normalizes `/` to `.` before resolving. A spelling whose literal would
        // look up another file's key than the one Laravel renders declines: surrounding whitespace
        // (the finder trims) and a second `::` (normalize() keeps only the first two segments).
        // The taint bridge opts in to the trim instead, because it keys writes by the name Laravel
        // really resolves, which is the trimmed one.
        //
        // A namespaced `pkg::name` needs nothing more: the compile pass registers a hint-root template
        // under exactly that qualified name, and only when the finder resolves the name to it
        // ({@see \Psalm\LaravelPlugin\Blade\BladeBootstrapper}). An unknown hint, a vendor/ hint
        // root, or an uncompiled template has no registry entry, and every consumer declines then.
        $viewName = ViewName::normalize($name->value);

        if ($trimName) {
            $viewName = \trim($viewName);
        }

        if ($viewName === '' || $viewName !== \trim($viewName) || \substr_count($name->value, '::') > 1) {
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

        return new self($viewName, $data, $complete, [], $binder);
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
     *
     * A subclass counts only while the called method is still the one a Laravel class declares. An
     * override can bind any view, or none: `parent::make('other', $data)` renders `other`, and a
     * response factory's `view()` may return an object whose `with()` ignores the data.
     */
    private static function roleOfReceiver(Expr $receiver, StatementsSource $source, string $method): ?string
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

            if ($role === null) {
                continue;
            }

            $declaring = $storage->declaring_method_ids[$method] ?? null;

            return $declaring !== null && ViewNameSignatures::resolveRole($declaring->fq_class_name) === $role ? $role : null;
        }

        return null;
    }

    /**
     * The one class the receiver is typed as, or null for a union, an intersection or an unknown type.
     */
    private static function receiverClass(Expr $receiver, StatementsSource $source): ?string
    {
        $atomic = self::soleAtomic($receiver, $source);

        return $atomic instanceof TNamedObject ? $atomic->value : null;
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
