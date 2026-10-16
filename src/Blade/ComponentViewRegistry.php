<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Views\ComponentRenderData;
use Psalm\LaravelPlugin\Internal\Ast\ClassMethodResolver;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Which compiled shadow is the own view of a Blade class component, and the variables
 * `Component::data()` hands that view when the component is rendered as `<x-…>` (#1804).
 *
 * Built once at `AfterCodebasePopulated` (class storage is complete, workers not yet forked) from
 * Psalm's own storage and the declaring `render()`'s AST. A view is typed only when ALL of these
 * hold, anything else leaves it on the prelude's `mixed`:
 *
 *  - exactly one non-abstract `Component` subclass maps to the template, through a `render()` whose
 *    whole body is `return view('lit', …)` / `view()->make('lit', …)` / `View::make('lit', …)` /
 *    `$this->view('lit', …)` / `'lit'`, with literal-keyed data arrays, and whose `$this->view()`
 *    is still `Component::view()`;
 *  - no other project or tagged component's `render()` outside that shape spells the same view name;
 *  - a compiled `<x-…>` tag somewhere renders that class ({@see ComponentTagCollector}): the
 *    `data()` keys only exist on that path, never on a manual `$component->render()`;
 *  - no compiled template renders the view by name (`@include`, `@each`, `@extends`,
 *    `@component('view')`), which hands it none of the `data()` keys;
 *  - the key set is knowable ({@see ComponentRenderData::guaranteedFor()}).
 *
 * Then each guaranteed key is seeded, except a key `render()` itself passes (path A and B disagree
 * on it), a literal named-slot name (a caller's slot overrides the key), an ambient or
 * slot-owned name, and a name the template declares itself.
 *
 * Every view any component's `render()` may name, seeded or not, also gets each `@props` entry the
 * prelude typed from its literal default reset to `mixed`.
 *
 * A Livewire component's view takes the same path (#1935) with Livewire's render semantics, read off
 * `HandleComponents::getView()`: no tag is needed (Livewire always calls `render()`), the seed is
 * `$this` plus the public properties ({@see ComponentRenderData::livewirePropertiesFor()}), which
 * `Utils::generateBladeView()` merges after `render()`'s own data, so they keep a key `render()` also
 * passes, though not one a view composer passes (it runs later). A Volt component only contests its
 * view, as any view a `placeholder()` spells does.
 *
 * Static because Psalm instantiates event handlers itself and hands them no plugin state.
 *
 * @internal
 */
final class ComponentViewRegistry
{
    private const COMPONENT = 'illuminate\view\component';

    private const VIEW_FACADE = 'illuminate\support\facades\view';

    private const LIVEWIRE = 'livewire\component';

    private const VOLT = 'livewire\volt\component';

    /**
     * View calls that cannot change what a Livewire view sees of its seed: `with()` data loses to the
     * properties merged after it, and the `SupportPageComponents` macros configure only the layout.
     */
    private const LIVEWIRE_TRANSPARENT = [
        'with' => true, 'layout' => true, 'extends' => true, 'section' => true, 'title' => true,
        'slot' => true, 'layoutdata' => true, 'response' => true,
    ];

    /** Set by `ManagesComponents::componentData()` after `data()`, so never `data()`'s to type. */
    private const SLOT_OWNED = ['slot' => true, '__laravel_slots' => true];

    /** @var array<array-key, true> lowercased classes a compiled tag renders */
    private static array $tagRendered = [];

    /** @var array<array-key, true> literal named-slot names any compiled template passes */
    private static array $namedSlots = [];

    /** @var array<array-key, true> view names any compiled template renders by name */
    private static array $renderedByName = [];

    /** @var array<string, non-empty-array<string, Union>> shadow path => variable name => type */
    private static array $seeds = [];

    /** @var array<string, string> shadow path => the Livewire component its view is bound to */
    private static array $bound = [];

    /** @var array<string, array<string, true>> shadow path => `__get`/`__call`, when that component declares it */
    private static array $boundMagic = [];

    /** @var array<lowercase-string, bool> namespaced function id => declared somewhere */
    private static array $declaredFunctions = [];

    /**
     * @param array<array-key, true> $tagRenderedClasses lowercased class => true
     * @param array<array-key, true> $namedSlots         slot name => true
     * @param array<array-key, true> $renderedByName     view name => true
     */
    public static function registerTagUsage(array $tagRenderedClasses, array $namedSlots, array $renderedByName): void
    {
        self::$tagRendered = $tagRenderedClasses;
        self::$namedSlots = $namedSlots;
        self::$renderedByName = $renderedByName;
    }

    /**
     * @return non-empty-array<string, Union>|null variable name (without $) => type
     */
    public static function seedFor(string $shadowPath): ?array
    {
        return self::$seeds[$shadowPath] ?? null;
    }

    /** The Livewire component a seeded view runs bound to: `$this`, and the scope its members are read from. */
    public static function boundClassFor(string $shadowPath): ?string
    {
        return self::$bound[$shadowPath] ?? null;
    }

    /**
     * Whether the Livewire component a view is bound to declares `$magic` (`__get` or `__call`), itself
     * or through an ancestor.
     */
    public static function boundClassDeclares(string $shadowPath, string $magic): bool
    {
        return isset(self::$boundMagic[$shadowPath][$magic]);
    }

    public static function build(Codebase $codebase): void
    {
        self::$seeds = [];
        self::$bound = [];
        self::$boundMagic = [];
        // Not skipped without a single `<x-…>` tag: a class's own view still needs its `@props`
        // defaults undone below, whichever way it is rendered.
        $templates = ViewReferenceRegistry::templates();

        /** @var array<string, array<string, array{0: ClassLikeStorage, 1: array<array-key, true>, 2: string}>> $byTemplate template path => class => [storage, render() keys, view name] */
        $byTemplate = [];
        /** @var array<string, true> $contested template path => true */
        $contested = [];

        foreach (\array_keys(self::$renderedByName) as $viewName) {
            $templatePath = $templates[$viewName] ?? null;

            if ($templatePath !== null) {
                $contested[$templatePath] = true;
            }
        }

        /** @var array<string, array{0: string, 1: array<array-key, true>, 2: bool}|null> $views declaring class => [view name, render() keys, calls $this->view()] */
        $views = [];
        /** @var array<string, true> $claimed template path => true, every view a component's render() may name */
        $claimed = [];
        /** @var array<string, true> $rivals template path => true, spelled by a render() outside the recognised shape */
        $rivals = [];
        /** @var array<string, true> $placeholders declaring Livewire placeholder() => true, already read */
        $placeholders = [];

        foreach (ClassLikeStorageProvider::getAll() as $storage) {
            $livewire = isset($storage->parent_classes[self::LIVEWIRE]);

            if ($storage->abstract || (!$livewire && !isset($storage->parent_classes[self::COMPONENT]))) {
                continue;
            }

            // Lazy loading renders a literal placeholder() view with this component's properties.
            $placeholder = $livewire ? ($storage->declaring_method_ids['placeholder'] ?? null) : null;

            if ($placeholder instanceof MethodIdentifier && !isset($placeholders[(string) $placeholder])) {
                $placeholders[(string) $placeholder] = true;
                $resolved = self::declaredInProject($codebase, $placeholder) ? ClassMethodResolver::resolve($codebase, $placeholder) : null;
                self::contestNamedViews($resolved['classMethod']->stmts ?? [], $templates, $rivals);
            }

            $render = $storage->declaring_method_ids['render'] ?? null;

            if (!$render instanceof MethodIdentifier) {
                continue;
            }

            $declaring = \strtolower($render->fq_class_name);

            if ($declaring === self::COMPONENT || $declaring === self::LIVEWIRE) {
                continue;
            }

            // An untagged component matters only as a rival claim on a project template; one from a
            // package renders that package's own views, so its file is not worth a parse.
            if (($livewire || !isset(self::$tagRendered[\strtolower($storage->name)])) && !self::declaredInProject($codebase, $render)) {
                continue;
            }

            // Keyed by flavour too: a trait's render() can serve both kinds, and only Livewire's macros peel.
            $viewKey = $declaring . ($livewire ? '#livewire' : '');

            if (!\array_key_exists($viewKey, $views)) {
                $views[$viewKey] = self::renderedView($codebase, $render, $templates, $rivals, $livewire);
            }

            $view = $views[$viewKey];
            $templatePath = $view === null ? null : ($templates[$view[0]] ?? null);

            if ($view === null || $templatePath === null) {
                continue;
            }

            $claimed[$templatePath] = true;

            // Volt renders through its own view resolution. (An anonymous class declaring render(), a
            // Livewire 4 single- or multi-file component, never gets here: with no `location` it is not
            // a project class.)
            if ($livewire && isset($storage->parent_classes[self::VOLT])) {
                $contested[$templatePath] = true;

                continue;
            }

            // An inherited `$this->view()` runs the CONCRETE class's view(), which may be overridden.
            if ($view[2] && !self::componentOwnsView($storage)) {
                $contested[$templatePath] = true;

                continue;
            }

            $byTemplate[$templatePath][\strtolower($storage->name)] = [$storage, $view[1], $view[0]];
        }

        $contested += $rivals;
        $claimed += $rivals;

        $shadowPaths = [];

        foreach (ShadowRegistry::shadowPaths() as $shadowPath) {
            $entry = ShadowRegistry::entryFor($shadowPath);

            if ($entry instanceof ShadowEntry) {
                $shadowPaths[$entry->templatePath] = $shadowPath;
            }
        }

        $parser = new ContractParser();

        foreach (\array_keys($claimed) as $templatePath) {
            $shadowPath = $shadowPaths[$templatePath] ?? null;
            $source = $shadowPath === null ? null : ShadowRegistry::templateSource($templatePath);
            $defaults = [];

            // `data()` and `render()`'s own data reach a class's view before `@props` falls back to a
            // default, so no literal default may type it (#1828); seeding `mixed` undoes the prelude's.
            foreach ($source === null ? [] : $parser->parseDeclarations($source)->vars as $name => $var) {
                if ($var->defaultType !== null) {
                    $defaults[$name] = Type::getMixed();
                }
            }

            if ($shadowPath !== null && $defaults !== []) {
                self::$seeds[$shadowPath] = $defaults;
            }
        }

        foreach ($byTemplate as $templatePath => $claims) {
            $shadowPath = $shadowPaths[$templatePath] ?? null;

            // Two classes rendering one view hand it different data (whether or not they share a
            // render()); seeding either would be a guess.
            if ($shadowPath === null || isset($contested[$templatePath]) || \count($claims) !== 1) {
                continue;
            }

            [$storage, $renderKeys, $viewName] = \reset($claims);
            $livewire = isset($storage->parent_classes[self::LIVEWIRE]);

            if (!$livewire && !isset(self::$tagRendered[\strtolower($storage->name)])) {
                continue;
            }

            $vars = self::seedVariables($codebase, $storage, $renderKeys, $templatePath, $parser);

            if ($livewire && $vars !== null) {
                // A composer runs on `composing`, after Livewire's `with()`, so a key it passes replaces
                // the property; an unreadable one may replace any.
                $sources = ContractRegistry::composerSources();
                $composed = \array_key_exists($viewName, $sources) ? ComposerKeyResolver::keysOf($codebase, $sources[$viewName]) : [];
                $vars = $composed === null ? [] : \array_diff_key($vars, $composed);
                $vars['this'] = new Union([new TNamedObject($storage->name)]);
                self::$bound[$shadowPath] = $storage->name;
                self::$boundMagic[$shadowPath] = \array_fill_keys(
                    \array_keys(\array_intersect_key($storage->declaring_method_ids, ['__get' => true, '__call' => true])),
                    true,
                );
            }

            $vars = ($vars ?? []) + (self::$seeds[$shadowPath] ?? []);

            if ($vars !== []) {
                self::$seeds[$shadowPath] = $vars;
            }
        }
    }

    public static function reset(): void
    {
        self::$tagRendered = [];
        self::$namedSlots = [];
        self::$renderedByName = [];
        self::$seeds = [];
        self::$bound = [];
        self::$boundMagic = [];
        self::$declaredFunctions = [];
    }

    /**
     * @param array<array-key, true> $renderKeys
     *
     * @return array<string, Union>|null null when the class's data is not knowable
     */
    private static function seedVariables(
        Codebase $codebase,
        ClassLikeStorage $storage,
        array $renderKeys,
        string $templatePath,
        ContractParser $parser,
    ): ?array {
        $source = ShadowRegistry::templateSource($templatePath);
        $guaranteed = isset($storage->parent_classes[self::LIVEWIRE])
            ? ComponentRenderData::livewirePropertiesFor($codebase, $storage)
            : ComponentRenderData::guaranteedFor($codebase, $storage);

        if ($source === null || $guaranteed === null) {
            return null;
        }

        $contract = $parser->parseDeclarations($source)->vars;
        $raw = \array_flip(ContractParser::rawDeclaredNames($source));
        $vars = [];

        foreach ($guaranteed as $name => $type) {
            if (isset($renderKeys[$name])
                || isset(self::$namedSlots[$name])
                || isset(self::SLOT_OWNED[$name])
                || isset(PreludeBuilder::AMBIENT_TYPES[$name])
                || isset($contract[$name])
                || isset($raw[$name])
            ) {
                continue;
            }

            $vars[$name] = $type;
        }

        return $vars;
    }

    /**
     * The view a declaring `render()` returns, the keys its own data arrays pass, and whether it
     * goes through `$this->view()`. Null when the body is anything but one recognised `return`;
     * then any known view name it spells is marked contested, since that render may hand the same
     * view other data.
     *
     * @param array<array-key, string> $templates view name => template path
     * @param array<string, true>      $contested template path => true, appended to
     * @param bool                     $livewire  read the call as Livewire renders it: the data
     *                                            arguments and LIVEWIRE_TRANSPARENT calls do not matter
     *
     * @return array{0: string, 1: array<array-key, true>, 2: bool}|null
     */
    private static function renderedView(Codebase $codebase, MethodIdentifier $render, array $templates, array &$contested, bool $livewire): ?array
    {
        $resolved = ClassMethodResolver::resolve($codebase, $render);

        if ($resolved === null) {
            return null;
        }

        $stmts = $resolved['classMethod']->stmts ?? [];
        $only = \count($stmts) === 1 ? $stmts[0] : null;
        $returned = $only instanceof Stmt\Return_ ? $only->expr : null;

        while ($livewire && $returned instanceof Expr\MethodCall && $returned->name instanceof Identifier
            && isset(self::LIVEWIRE_TRANSPARENT[$returned->name->toLowerString()])
        ) {
            $returned = $returned->var;
        }

        $view = $returned instanceof Expr ? self::viewOf($codebase, $returned, $livewire) : null;

        if ($view === null) {
            self::contestNamedViews($stmts, $templates, $contested);
        }

        return $view;
    }

    /**
     * Marks every known view a method body spells, since it may render that view with other data.
     *
     * @param array<array-key, \PhpParser\Node> $stmts
     * @param array<array-key, string>          $templates view name => template path
     * @param array<string, true>               $contested template path => true, appended to
     */
    private static function contestNamedViews(array $stmts, array $templates, array &$contested): void
    {
        foreach ((new NodeFinder())->findInstanceOf($stmts, String_::class) as $string) {
            $templatePath = $templates[$string->value] ?? null;

            if ($templatePath !== null) {
                $contested[$templatePath] = true;
            }
        }
    }

    /**
     * @return array{0: string, 1: array<array-key, true>, 2: bool}|null
     */
    private static function viewOf(Codebase $codebase, Expr $expr, bool $livewire): ?array
    {
        // `Component::resolveView()` treats a returned string naming an existing view as that view.
        if ($expr instanceof String_) {
            return $expr->value === '' ? null : [$expr->value, [], false];
        }

        $viaThis = $expr instanceof Expr\MethodCall
            && self::isNamed($expr->name, 'view')
            && $expr->var instanceof Expr\Variable
            && $expr->var->name === 'this';

        $recognised = $viaThis
            || ($expr instanceof Expr\FuncCall && self::isViewHelper($codebase, $expr))
            || ($expr instanceof Expr\StaticCall
                && $expr->class instanceof Name
                && \strtolower(self::resolvedName($expr->class)) === self::VIEW_FACADE
                && self::isNamed($expr->name, 'make'))
            // `view()->make(…)`: the helper's no-argument form returns the factory.
            || ($expr instanceof Expr\MethodCall
                && self::isNamed($expr->name, 'make')
                && $expr->var instanceof Expr\FuncCall
                && $expr->var->args === []
                && self::isViewHelper($codebase, $expr->var));

        $args = $recognised && $expr instanceof Expr\CallLike ? self::viewArgs($expr, $livewire) : null;

        return $args === null ? null : [$args[0], $args[1], $viaThis];
    }

    /**
     * Name, data, and merge-data arguments, positional and literal only.
     *
     * @param bool $anyData accept any positional data argument, and report no keys
     *
     * @return array{0: string, 1: array<array-key, true>}|null
     */
    private static function viewArgs(Expr\CallLike $call, bool $anyData): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $args = $call->getRawArgs();

        if ($args === [] || \count($args) > 3) {
            return null;
        }

        $keys = [];

        foreach ($args as $position => $arg) {
            if (!$arg instanceof Arg || $arg->unpack || $arg->name instanceof Identifier) {
                return null;
            }

            if ($position === 0) {
                if (!$arg->value instanceof String_ || $arg->value->value === '') {
                    return null;
                }

                continue;
            }

            if ($anyData) {
                continue;
            }

            if (!$arg->value instanceof Expr\Array_) {
                return null;
            }

            foreach ($arg->value->items as $item) {
                if ($item === null || $item->unpack || !$item->key instanceof String_) {
                    return null;
                }

                $keys[$item->key->value] = true;
            }
        }

        $name = $args[0];

        return $name instanceof Arg && $name->value instanceof String_ ? [$name->value->value, $keys] : null;
    }

    /** The global `view()` helper, not a same-named function of the calling namespace. */
    private static function isViewHelper(Codebase $codebase, Expr\FuncCall $call): bool
    {
        if (!$call->name instanceof Name || \strtolower($call->name->getLast()) !== 'view') {
            return false;
        }

        /** @psalm-var ?string $resolved */
        $resolved = $call->name->getAttribute('resolvedName');

        if (\is_string($resolved)) {
            return \strtolower(\ltrim($resolved, '\\')) === 'view';
        }

        if ($call->name->isFullyQualified() || $call->name->isQualified()) {
            return \strtolower($call->name->toString()) === 'view';
        }

        /** @psalm-var ?string $namespaced */
        $namespaced = $call->name->getAttribute('namespacedName');

        if (!\is_string($namespaced) || \strtolower(\ltrim($namespaced, '\\')) === 'view') {
            return true;
        }

        $functionId = \strtolower(\ltrim($namespaced, '\\'));

        if (!\array_key_exists($functionId, self::$declaredFunctions)) {
            self::$declaredFunctions[$functionId] = $codebase->functions->hasStubbedFunction($functionId)
                || $codebase->functions->isDeclaredInCode($functionId);
        }

        return !self::$declaredFunctions[$functionId];
    }

    /**
     * @psalm-mutation-free
     */
    private static function componentOwnsView(ClassLikeStorage $storage): bool
    {
        $view = $storage->declaring_method_ids['view'] ?? null;

        return $view instanceof MethodIdentifier && \strtolower($view->fq_class_name) === self::COMPONENT;
    }

    /**
     * @psalm-mutation-free
     */
    private static function declaredInProject(Codebase $codebase, MethodIdentifier $render): bool
    {
        try {
            $file = $codebase->classlike_storage_provider->get(\strtolower($render->fq_class_name))->location?->file_path;
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $file !== null && $codebase->config->isInProjectDirs($file);
    }

    private static function resolvedName(Name $name): string
    {
        /** @psalm-var ?string $resolved */
        $resolved = $name->getAttribute('resolvedName');

        return \is_string($resolved) ? \ltrim($resolved, '\\') : $name->toString();
    }

    private static function isNamed(Expr|Identifier $name, string $lowercase): bool
    {
        return $name instanceof Identifier && $name->toLowerString() === $lowercase;
    }
}
