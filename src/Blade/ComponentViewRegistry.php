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
 * Static because Psalm instantiates event handlers itself and hands them no plugin state.
 *
 * @internal
 */
final class ComponentViewRegistry
{
    private const COMPONENT = 'illuminate\view\component';

    private const VIEW_FACADE = 'illuminate\support\facades\view';

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

    public static function build(Codebase $codebase): void
    {
        self::$seeds = [];

        if (self::$tagRendered === []) {
            return;
        }

        $templates = ViewReferenceRegistry::templates();

        /** @var array<string, array<string, array{0: ClassLikeStorage, 1: array<array-key, true>}>> $byTemplate template path => class => [storage, render() keys] */
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

        foreach (ClassLikeStorageProvider::getAll() as $storage) {
            if ($storage->abstract || !isset($storage->parent_classes[self::COMPONENT])) {
                continue;
            }

            $render = $storage->declaring_method_ids['render'] ?? null;

            if (!$render instanceof MethodIdentifier) {
                continue;
            }

            $declaring = \strtolower($render->fq_class_name);

            if ($declaring === self::COMPONENT) {
                continue;
            }

            // An untagged component matters only as a rival claim on a project template; one from a
            // package renders that package's own views, so its file is not worth a parse.
            if (!isset(self::$tagRendered[\strtolower($storage->name)]) && !self::declaredInProject($codebase, $render)) {
                continue;
            }

            if (!\array_key_exists($declaring, $views)) {
                $views[$declaring] = self::renderedView($codebase, $render, $templates, $contested);
            }

            $view = $views[$declaring];
            $templatePath = $view === null ? null : ($templates[$view[0]] ?? null);

            if ($view === null || $templatePath === null) {
                continue;
            }

            // An inherited `$this->view()` runs the CONCRETE class's view(), which may be overridden.
            if ($view[2] && !self::componentOwnsView($storage)) {
                $contested[$templatePath] = true;

                continue;
            }

            $byTemplate[$templatePath][\strtolower($storage->name)] = [$storage, $view[1]];
        }

        $shadowPaths = [];

        foreach (ShadowRegistry::shadowPaths() as $shadowPath) {
            $entry = ShadowRegistry::entryFor($shadowPath);

            if ($entry instanceof ShadowEntry) {
                $shadowPaths[$entry->templatePath] = $shadowPath;
            }
        }

        $parser = new ContractParser();

        foreach ($byTemplate as $templatePath => $claims) {
            $shadowPath = $shadowPaths[$templatePath] ?? null;

            // Two classes rendering one view hand it different data (whether or not they share a
            // render()); seeding either would be a guess.
            if ($shadowPath === null || isset($contested[$templatePath]) || \count($claims) !== 1) {
                continue;
            }

            [$storage, $renderKeys] = \reset($claims);

            if (!isset(self::$tagRendered[\strtolower($storage->name)])) {
                continue;
            }

            $vars = self::seedVariables($codebase, $storage, $renderKeys, $templatePath, $parser);

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
        self::$declaredFunctions = [];
    }

    /**
     * @param array<array-key, true> $renderKeys
     *
     * @return array<string, Union>
     */
    private static function seedVariables(
        Codebase $codebase,
        ClassLikeStorage $storage,
        array $renderKeys,
        string $templatePath,
        ContractParser $parser,
    ): array {
        $source = ShadowRegistry::templateSource($templatePath);
        $guaranteed = ComponentRenderData::guaranteedFor($codebase, $storage);

        if ($source === null || $guaranteed === null) {
            return [];
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
     *
     * @return array{0: string, 1: array<array-key, true>, 2: bool}|null
     */
    private static function renderedView(Codebase $codebase, MethodIdentifier $render, array $templates, array &$contested): ?array
    {
        $resolved = ClassMethodResolver::resolve($codebase, $render);

        if ($resolved === null) {
            return null;
        }

        $stmts = $resolved['classMethod']->stmts ?? [];
        $only = \count($stmts) === 1 ? $stmts[0] : null;
        $view = $only instanceof Stmt\Return_ && $only->expr instanceof Expr ? self::viewOf($codebase, $only->expr) : null;

        if ($view !== null) {
            return $view;
        }

        foreach ((new NodeFinder())->findInstanceOf($stmts, String_::class) as $string) {
            $templatePath = $templates[$string->value] ?? null;

            if ($templatePath !== null) {
                $contested[$templatePath] = true;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: array<array-key, true>, 2: bool}|null
     */
    private static function viewOf(Codebase $codebase, Expr $expr): ?array
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

        $args = $recognised && $expr instanceof Expr\CallLike ? self::viewArgs($expr) : null;

        return $args === null ? null : [$args[0], $args[1], $viaThis];
    }

    /**
     * Name, data, and merge-data arguments, positional and literal only.
     *
     * @return array{0: string, 1: array<array-key, true>}|null
     */
    private static function viewArgs(Expr\CallLike $call): ?array
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
