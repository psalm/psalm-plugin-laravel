<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\View\ViewName;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\ClosureAnalyzer;
use Psalm\Internal\Analyzer\FunctionAnalyzer;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\MethodAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\TraitAnalyzer;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\StatementsSource;
use Psalm\Storage\MethodStorage;
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

        // No keys added, but the set is no longer closed: the helper's caller completes the view.
        if (self::isInNonPublicHelper($source)) {
            $chain = $chain->supplying([[], false]);
        }

        // `Component::data()` and Livewire's properties are merged into the view `render()` returns, a
        // `view()` or factory view, and no other: a mail view built on the side receives none of them.
        if ($chain->binder !== null && $chain->binder !== ViewNameSignatures::ROLE_VIEW_FACTORY) {
            return $chain;
        }

        $inRender = self::isInRenderMethod($source);
        $merged = ComponentRenderData::forSource($source);

        if ($merged === null) {
            // Livewire renders its `placeholder()` and other views without the properties.
            return $inRender ? $chain->supplying(FrameworkViewData::forLivewire($source)) : $chain;
        }

        if (!$inRender) {
            // A view another method builds (a public helper `render()` returns) may reach `render()`: the
            // component's names count as supplied, but nothing can be said about their types.
            return $chain->supplying([FrameworkViewData::untyped($merged[0]) + ($merged[1] ? [] : FrameworkViewData::untyped($chain->data)), $merged[1]]);
        }

        // A component's public `render()` can be called, and its result chained, from anywhere
        // (`$component->render()->with([...])`): the data added there is not in this chain.
        //
        // An unknowable key set (`data()` overridden) may replace any key the call passes, too.
        return $chain->supplying([$merged[1] ? $merged[0] : FrameworkViewData::untyped($chain->data) + $merged[0], false]);
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

        if (!self::isWith($call)) {
            return self::literalEntries(self::dataArgs($call, $args, $binder));
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
     * The data arrays THIS call passes that are not written out literally (a variable, `compact()`,
     * a call result): what {@see self::ownEntries()} cannot split into keys. A `with()` key that may
     * be an array is one too; a string key (literal or not) never is.
     *
     * @return list<Expr>
     */
    public static function ownArrays(Expr\CallLike $call, ?string $binder, StatementsSource $source): array
    {
        $args = self::args($call);

        if ($args === null) {
            return [];
        }

        if (self::isWith($call)) {
            $keyArg = ArgUtil::byNameOrPosition($args, 0, 'key');
            $isLiteral = $keyArg instanceof Arg && ($keyArg->value instanceof Expr\Array_ || $keyArg->value instanceof String_);

            return $keyArg instanceof Arg && !$isLiteral && self::mergesArray($keyArg, $source) !== false ? [$keyArg->value] : [];
        }

        $arrays = [];

        foreach (self::dataArgs($call, $args, $binder) as $arg) {
            if ($arg instanceof Arg && !$arg->value instanceof Expr\Array_) {
                $arrays[] = $arg->value;
            }
        }

        return $arrays;
    }

    /**
     * The `key => value expression` pairs of one array literal, the last write of a key winning.
     *
     * @return list<array{0: string, 1: Expr}>
     *
     * @psalm-capabilities read-props
     */
    public static function arrayEntries(Expr\Array_ $array): array
    {
        return self::entriesOf(self::collectLiteralKeys($array, []));
    }

    private static function isWith(Expr\CallLike $call): bool
    {
        return ($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall || $call instanceof Expr\StaticCall)
            && $call->name instanceof Identifier
            && $call->name->toLowerString() === 'with';
    }

    /**
     * The data arguments of a binder in the order PHP merges them (a later array wins a shared key):
     * `$mergeData`, then `$data`. `response()->view($view, $data, $status, $headers)` has no
     * `$mergeData`: position 2 is the status. A compiled `@includeWhen`/`@includeUnless` puts its
     * condition first, which shifts both.
     *
     * @param list<Arg> $args
     * @return list<Arg|null>
     */
    private static function dataArgs(Expr\CallLike $call, array $args, ?string $binder): array
    {
        $shift = $call instanceof Expr\MethodCall && $call->name instanceof Identifier
            && \in_array($call->name->toLowerString(), ['renderwhen', 'renderunless'], true) ? 1 : 0;
        $mergeData = $call instanceof Expr\FuncCall || $binder === ViewNameSignatures::ROLE_VIEW_FACTORY
            ? ArgUtil::byNameOrPosition($args, 2 + $shift, 'mergedata')
            : null;

        return [$mergeData, ArgUtil::byNameOrPosition($args, 1 + $shift, 'data')];
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

        return self::entriesOf($latest);
    }

    /**
     * @param array<array-key, Expr> $latest
     * @return list<array{0: string, 1: Expr}>
     *
     * @psalm-pure
     */
    private static function entriesOf(array $latest): array
    {
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
        $analyzer = self::enclosingFunctionLike($source);

        // `render()` may return a closure whose result Laravel merges the data into: a closure is part of
        // the method that holds it.
        while ($analyzer instanceof ClosureAnalyzer) {
            $analyzer = self::enclosingFunctionLike($analyzer->getSource());
        }

        return $analyzer instanceof FunctionLikeAnalyzer && \strtolower((string) $analyzer->getMethodName()) === 'render';
    }

    /**
     * A named function or a private/protected method is a helper its caller completes:
     * `function greetingView(): View { return view('greeting'); }` is called as
     * `greetingView()->with('name', $n)`. A closure stays checked (a route closure is the final
     * handler), and so does a public method (a controller action, a Mailable's `build()`).
     *
     * A trait method is analyzed once, as the trait, so its own visibility is not the effective
     * one: the classes using the trait may adapt it (`use T { action as public; }`).
     */
    private static function isInNonPublicHelper(StatementsSource $source): bool
    {
        $analyzer = self::enclosingFunctionLike($source);

        if ($analyzer instanceof FunctionAnalyzer) {
            return true;
        }

        if (!$analyzer instanceof MethodAnalyzer) {
            return false;
        }

        $storage = $analyzer->getStorage();

        if (!$storage instanceof MethodStorage) {
            return false;
        }

        if (!$analyzer->getSource() instanceof TraitAnalyzer) {
            return $storage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC;
        }

        try {
            return !self::mayBePublicThroughUsers(
                $source->getCodebase(),
                \strtolower((string) $analyzer->getFQCLN()),
                \strtolower((string) $analyzer->getMethodName()),
                $storage->visibility,
            );
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            // Not proven non-public: keep the view's key set closed.
            return false;
        }
    }

    /**
     * Whether a class using the trait exposes its method publicly: through the method's own name
     * (`use T { m as public; }`) or through an alias under a new name (`use T { m as public n; }`).
     * A trait used by another trait is followed to the classes using that one. A trait with no user
     * keeps its declared visibility.
     *
     * @param lowercase-string $traitLc
     * @param lowercase-string $methodLc
     * @param int $declared the visibility the trait (or the trait using it) gives the method
     * @throws UnpopulatedClasslikeException
     * @psalm-capabilities read-props|write-this-props
     */
    private static function mayBePublicThroughUsers(Codebase $codebase, string $traitLc, string $methodLc, int $declared): bool
    {
        $hasUser = false;

        foreach ($codebase->classlike_storage_provider::getAll() as $user) {
            if (!isset($user->used_traits[$traitLc])) {
                continue;
            }

            $hasUser = true;

            foreach ($user->trait_alias_map as $alias => $original) {
                if (
                    $original === $methodLc
                    && ($user->trait_visibility_map[$alias] ?? null) === ClassLikeAnalyzer::VISIBILITY_PUBLIC
                ) {
                    return true;
                }
            }

            $visibility = $user->trait_visibility_map[$methodLc] ?? $declared;

            if ($visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                return true;
            }

            if ($user->is_trait && self::mayBePublicThroughUsers($codebase, \strtolower($user->name), $methodLc, $visibility)) {
                return true;
            }
        }

        return !$hasUser && $declared === ClassLikeAnalyzer::VISIBILITY_PUBLIC;
    }

    /**
     * @psalm-pure
     */
    private static function enclosingFunctionLike(StatementsSource $source): ?FunctionLikeAnalyzer
    {
        $analyzer = $source instanceof StatementsAnalyzer ? $source->getSource() : null;

        return $analyzer instanceof FunctionLikeAnalyzer ? $analyzer : null;
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
                return self::bindsViewName($methodName)
                    ? self::fromBinder(self::roleOfStaticClass($node->class), null, $methodName, $args, $source, $pending, $trimName)
                    : null;
            }

            // Collected rather than folded in here: whether these carry template data at all depends
            // on the receiver the walk has not reached yet.
            if ($methodName === 'with' || $methodName === 'witherrors') {
                $pending[] = [$methodName, $args];
                $node = $node->var;

                continue;
            }

            // Cheapest bail first: resolving the receiver's ancestry costs a storage walk per call.
            if (!self::bindsViewName($methodName)) {
                return null;
            }

            return self::fromBinder(self::roleOfReceiver($node->var, $source, $methodName), self::receiverClass($node->var, $source), $methodName, $args, $source, $pending, $trimName);
        }
    }

    /**
     * The only method names {@see self::fromBinder()} binds a view with, whatever the receiver.
     *
     * @param lowercase-string $methodName
     *
     * @psalm-pure
     */
    private static function bindsViewName(string $methodName): bool
    {
        return \in_array($methodName, ['make', 'view', 'markdown'], true);
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

        $markdown = $methodName === 'markdown';

        return match ($role) {
            ViewNameSignatures::ROLE_MAILABLE => $chain?->supplying(FrameworkViewData::forMailable($receiverClass, $chain->data, $chain->complete, $markdown, $source)),
            ViewNameSignatures::ROLE_MAIL_MESSAGE => $chain?->supplying(
                FrameworkViewData::forMailMessage($receiverClass, $chain->data, $chain->complete, $markdown, $source),
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
        $target = self::includeTarget($call, $source);

        if ($target === null) {
            return null;
        }

        [$name, $at, $args, $methodName] = $target;

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
     * The views a compiled include may render, whatever shape its data takes. {@see self::fromInclude()}
     * refuses a non-literal data argument because it type-checks keys; a taint write follows any of
     * them, so it asks only for names. The data positions are {@see self::ownEntries()}'s.
     *
     * `first()` yields every literal candidate up to the first one known to exist: a candidate of
     * unknowable existence (namespaced) may be the one rendered, so it is included, and one provably
     * absent is skipped. A sound over-approximation, unlike the single view type-checking needs.
     *
     * @return list<string>
     */
    public static function includedViews(Expr\MethodCall $call, StatementsSource $source): array
    {
        $include = self::includeCall($call, $source);

        if ($include === null) {
            return [];
        }

        [$args, $methodName] = $include;

        $names = match ($methodName) {
            'make' => [self::viewArg($args)],
            'renderwhen', 'renderunless' => [ArgUtil::byNameOrPosition($args, 1, 'view')?->value],
            'first' => self::mayBeRendered(self::literalCandidates(ArgUtil::byNameOrPosition($args, 0, 'views'))),
            default => [],
        };

        $views = [];

        foreach ($names as $name) {
            $view = self::build($name, null, $source, [], true, true, null, true)?->viewName;

            if ($view !== null) {
                $views[] = $view;
            }
        }

        return $views;
    }

    /**
     * The candidates of an `Factory::first()` that could be the rendered one: in order, up to and
     * including the first known to exist, leaving out those provably absent.
     *
     * @param list<String_> $candidates
     * @return list<String_>
     */
    private static function mayBeRendered(array $candidates): array
    {
        $possible = [];

        foreach ($candidates as $candidate) {
            $exists = MissingViewHandler::viewExists($candidate->value);

            if ($exists === false) {
                continue;
            }

            $possible[] = $candidate;

            if ($exists === true) {
                break;
            }
        }

        return $possible;
    }

    /**
     * The arguments and lower-cased method of a call on a view factory, or null for any other receiver.
     *
     * @return array{0: list<Arg>, 1: string}|null
     */
    private static function includeCall(Expr\MethodCall $call, StatementsSource $source): ?array
    {
        $args = self::args($call);

        if ($args === null || !$call->name instanceof Identifier) {
            return null;
        }

        $methodName = $call->name->toLowerString();

        // Resolved only now: the receiver's role depends on which method is called on it.
        return self::roleOfReceiver($call->var, $source, $methodName) === ViewNameSignatures::ROLE_VIEW_FACTORY ? [$args, $methodName] : null;
    }

    /**
     * @return array{0: ?Expr, 1: int, 2: list<Arg>, 3: string}|null the view name expression, the
     *                                                              argument position before `$data`, the
     *                                                              arguments and the lower-cased method
     */
    private static function includeTarget(Expr\MethodCall $call, StatementsSource $source): ?array
    {
        $include = self::includeCall($call, $source);

        if ($include === null) {
            return null;
        }

        [$args, $methodName] = $include;

        [$name, $at] = match ($methodName) {
            'make' => [self::viewArg($args), 0],
            'renderwhen', 'renderunless' => [ArgUtil::byNameOrPosition($args, 1, 'view')?->value, 1],
            'first' => [self::firstExistingCandidate(ArgUtil::byNameOrPosition($args, 0, 'views')), 0],
            default => [null, 0],
        };

        return [$name, $at, $args, $methodName];
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
        $candidates = self::literalCandidates($views);

        foreach ($candidates as $candidate) {
            $exists = MissingViewHandler::viewExists($candidate->value);

            if ($exists !== false) {
                return $exists === true ? $candidate : null;
            }
        }

        return null;
    }

    /**
     * The candidates of a list made only of plain, already trimmed string literals; empty for any
     * other list. A key anywhere can overwrite an earlier element, and `FileViewFinder::find()` trims
     * a name, so `' inc.card '` may render `inc.card` while a lookup of the padded name misses it.
     *
     * @return list<String_>
     *
     * @psalm-capabilities read-props
     */
    private static function literalCandidates(?Arg $views): array
    {
        if (!$views instanceof Arg || !$views->value instanceof Expr\Array_) {
            return [];
        }

        $candidates = [];

        foreach ($views->value->items as $item) {
            if ($item === null
                || $item->key instanceof Expr
                || !$item->value instanceof String_
                || $item->value->value !== \trim($item->value->value)
            ) {
                return [];
            }

            $candidates[] = $item->value;
        }

        return $candidates;
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

        // Once a contribution that may replace any key was seen (a computed key, an array of unknown
        // shape), every key recorded after it was bound BEFORE it ran, so its type is not provably
        // what the template receives.
        /** @var bool|array<string, true> $mayOverwrite */
        $mayOverwrite = false;

        // The chain's contributions land before the binder's own data argument, because the walk ran
        // outermost-first and `??=` below lets the first contribution seen — the last one to run — win.
        if ($carriesData && !self::mergePending($pending, $source, $data, $complete, $mayOverwrite)) {
            return null;
        }

        if ($dataArg instanceof Arg) {
            self::mergeArrayArg($dataArg, $source, $data, $complete, $mayOverwrite);
        }

        return new self($viewName, $data, $complete, [], $binder);
    }

    /**
     * @param list<array{0: 'with'|'witherrors', 1: list<Arg>}> $pending
     * @param array<string, Union>                             $data
     * @param bool|array<string, true>                         $mayOverwrite see {@see self::record()}
     */
    private static function mergePending(array $pending, StatementsSource $source, array &$data, bool &$complete, bool|array &$mayOverwrite): bool
    {
        foreach ($pending as [$methodName, $args]) {
            if ($methodName === 'witherrors') {
                // withErrors() forwards to with('errors', ...) — the key is fixed, and the value is
                // a ViewErrorBag no template declares a type for, so mixed carries enough.
                $data['errors'] ??= Type::getMixed();

                continue;
            }

            if (!self::mergeWith($args, $source, $data, $complete, $mayOverwrite)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `with()` dispatches on `is_array($key)`, never on the argument count: `with('name')` really
     * does assign null to 'name', and `with(['name' => $n], $ignored)` still merges the array.
     *
     * @param list<Arg>                $args
     * @param array<string, Union>     $data
     * @param bool|array<string, true> $mayOverwrite see {@see self::record()}
     */
    private static function mergeWith(array $args, StatementsSource $source, array &$data, bool &$complete, bool|array &$mayOverwrite): bool
    {
        $keyArg = ArgUtil::byNameOrPosition($args, 0, 'key');

        if (!$keyArg instanceof Arg) {
            return false;
        }

        $merges = self::mergesArray($keyArg, $source);

        if ($merges === true) {
            self::mergeArrayArg($keyArg, $source, $data, $complete, $mayOverwrite);

            return true;
        }

        if ($merges === null || !$keyArg->value instanceof String_) {
            // Either branch could run, or the key names a variable we cannot read: nothing the
            // template declares is provably absent, and nothing is provably present either.
            $complete = false;
            $mayOverwrite = true;

            return true;
        }

        $valueArg = ArgUtil::byNameOrPosition($args, 1, 'value');

        // Walking outermost-first means the first contribution seen is the last one to run, and
        // Laravel lets the last write win.
        self::record($data, $keyArg->value->value, $valueArg instanceof Arg
            ? $source->getNodeTypeProvider()->getType($valueArg->value) ?? Type::getMixed()
            : Type::getNull(), $mayOverwrite);

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
     * @param array<string, Union>     $data
     * @param bool|array<string, true> $mayOverwrite see {@see self::record()}
     */
    private static function mergeArrayArg(Arg $arg, StatementsSource $source, array &$data, bool &$complete, bool|array &$mayOverwrite): void
    {
        if ($arg->unpack) {
            $complete = false;
            $mayOverwrite = true;

            return;
        }

        $atomic = self::soleAtomic($arg->value, $source);

        if (!$atomic instanceof Atomic) {
            $complete = false;
            $mayOverwrite = true;

            return;
        }

        // `[]` infers as an empty TArray rather than a keyed array, and it is the one array shape
        // that proves the data set is empty.
        if ($atomic instanceof TArray && $atomic->isEmptyArray()) {
            return;
        }

        if (!$atomic instanceof TKeyedArray) {
            $complete = false;
            $mayOverwrite = true;

            return;
        }

        // Applied after this array's own keys: they are what the array itself holds, and only the
        // contributions bound before it can be replaced by a key it may carry.
        $opensKeys = $atomic->fallback_params !== null;

        if ($opensKeys) {
            // A spread, or an inferred fallback, leaves room for keys we cannot see.
            $complete = false;
        }

        $optional = [];

        foreach ($atomic->properties as $key => $valueType) {
            if (!\is_string($key)) {
                continue;
            }

            if ($valueType->possibly_undefined) {
                // A closed shape can replace only its own keys, optional ones included.
                $complete = false;
                $optional[$key] = true;

                continue;
            }

            self::record($data, $key, $valueType, $mayOverwrite);
        }

        if ($opensKeys) {
            $mayOverwrite = true;
        } elseif ($optional !== [] && $mayOverwrite !== true) {
            $mayOverwrite = \is_array($mayOverwrite) ? $mayOverwrite + $optional : $optional;
        }
    }

    /**
     * The first write seen of a key wins (the walk is outermost-first, so it is the last one to run).
     * After a contribution that may replace any key, that write was bound earlier and is not
     * provably what the template receives: the key is still supplied, with an unknown type.
     *
     * @param array<string, Union>           $data
     * @param bool|array<string, true> $mayOverwrite every key (true), only the listed ones, or none
     *
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    private static function record(array &$data, string $key, Union $type, bool|array $mayOverwrite): void
    {
        $data[$key] ??= $mayOverwrite === true || isset($mayOverwrite[$key]) ? Type::getMixed() : $type;
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

        // An intersection is not provably one class.
        return $atomic instanceof TNamedObject && $atomic->extra_types === [] ? $atomic->value : null;
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
