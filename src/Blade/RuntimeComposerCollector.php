<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Support\Str;
use Illuminate\View\ViewName;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;

/**
 * Finds the view composers and creators project code registers while a request runs (middleware,
 * controllers, route closures), which {@see ComposerSourceReader} cannot see at boot, and marks every
 * contracted view they reach as composed by an unreadable source.
 *
 * Only a call whose receiver is provably the view factory counts: the `View` facade, `view()`,
 * `app('view')`, or a variable or property typed as the factory. A `composer()` on any other class
 * is not a view registration. A call directly in a method of a service provider the app loaded ran
 * at boot, so the boot reader already has it; one in a closure there runs later and counts.
 *
 * A view argument that is not a literal could name any view, so every contracted view is marked.
 *
 * @internal
 */
final class RuntimeComposerCollector extends NodeVisitorAbstract
{
    private const FACTORIES = ['illuminate\\view\\factory', 'illuminate\\contracts\\view\\factory'];

    /** Container keys are matched as Laravel matches them, case and all. */
    private const CONTAINER_KEYS = ['view', 'Illuminate\\View\\Factory', 'Illuminate\\Contracts\\View\\Factory'];

    private string $namespace = '';

    /** @var array<string, string> lowercased alias => imported class */
    private array $uses = [];

    /** @var list<array{0: string, 1: array<string, true>}> enclosing class (lowercased, '' when anonymous) => factory-typed properties */
    private array $classes = [];

    /**
     * @var list<array{0: array<string, bool>, 1: bool}> variable => whether it holds the factory (a
     *      non-factory entry shadows an outer one), and whether the scope is an arrow function; the
     *      first is the file's top-level code
     */
    private array $scopes = [[[], false]];

    /** @var list<string> */
    private array $patterns = [];

    private bool $unknown = false;

    /**
     * @param list<string> $bootProviders service provider classes the app loaded
     * @param bool $rootViewIsFacade whether the global `View` name is the facade's alias
     *
     * @psalm-mutation-free
     */
    private function __construct(
        private readonly array $bootProviders,
        private readonly bool $rootViewIsFacade,
    ) {}

    public static function markComposedViews(Codebase $codebase): void
    {
        $patterns = self::collect($codebase);

        foreach (ContractRegistry::viewNames() as $viewName) {
            if ($patterns === null || self::matches($patterns, $viewName)) {
                ContractRegistry::registerComposerSources($viewName, null);
            }
        }
    }

    /** @param list<string> $patterns */
    private static function matches(array $patterns, string $viewName): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $viewName)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null view name patterns, null when one registration's views cannot be named */
    private static function collect(Codebase $codebase): ?array
    {
        try {
            $loaded = \array_keys(ApplicationProvider::getApp()->getLoadedProviders());
        } catch (\Throwable) {
            $loaded = [];
        }

        try {
            // Autoloading `View` runs the booted app's AliasLoader; a userland global `View` is no facade.
            $rootViewIsFacade = \class_exists('View') && \is_a('View', \Illuminate\Support\Facades\View::class, true);
        } catch (\Throwable) {
            $rootViewIsFacade = false;
        }

        $patterns = [];

        foreach (\array_keys($codebase->scanner->getScannedFiles()) as $path) {
            if (!$codebase->config->isInProjectDirs($path)) {
                continue;
            }

            try {
                $contents = $codebase->file_provider->getContents($path);

                // Parsing is the cost; a file that never spells the method names cannot call them.
                if (\stripos($contents, 'composer') === false && \stripos($contents, 'creator') === false) {
                    continue;
                }

                $found = self::patternsIn($codebase->getStatementsForFile($path), $loaded, $rootViewIsFacade);
            } catch (\Throwable $throwable) {
                $codebase->progress->debug(
                    "Laravel plugin: reading runtime view composers from {$path} threw: {$throwable->getMessage()}; "
                    . "no view data contract is checked this run\n",
                );

                return null;
            }

            if ($found === null) {
                return null;
            }

            $patterns = [...$patterns, ...$found];
        }

        return $patterns;
    }

    /**
     * @param list<Stmt> $stmts one file's statements, with Psalm's resolved-name attributes
     * @param list<string> $bootProviders service provider classes the app loaded
     *
     * @return list<string>|null
     */
    public static function patternsIn(array $stmts, array $bootProviders = [], bool $rootViewIsFacade = true): ?array
    {
        $collector = new self($bootProviders, $rootViewIsFacade);
        (new NodeTraverser($collector))->traverse($stmts);

        return $collector->unknown ? null : $collector->patterns;
    }

    #[\Override]
    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name instanceof Name ? $node->name->toString() . '\\' : '';
            $this->uses = [];
        } elseif ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            $prefix = $node instanceof Stmt\GroupUse ? $node->prefix->toString() . '\\' : '';

            foreach ($node->uses as $use) {
                if ($node->type === Stmt\Use_::TYPE_NORMAL || $use->type === Stmt\Use_::TYPE_NORMAL) {
                    $this->uses[$use->getAlias()->toLowerString()] = $prefix . $use->name->toString();
                }
            }
        } elseif ($node instanceof Stmt\ClassLike) {
            $this->classes[] = [$node->name instanceof Identifier ? \strtolower($this->namespace . $node->name->name) : '', $this->factoryProperties($node)];
        } elseif ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            $variables = [];

            foreach ($node->params as $param) {
                if ($param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                    $variables[$param->var->name] = $this->isFactoryType($param->type);
                }
            }

            foreach ($node instanceof Expr\Closure ? $node->uses : [] as $use) {
                if (\is_string($use->var->name)) {
                    $variables[$use->var->name] = $this->isFactoryVariable($use->var->name);
                }
            }

            $this->scopes[] = [$variables, $node instanceof Expr\ArrowFunction];
        } elseif ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && \is_string($node->var->name)) {
            $factory = $this->isFactory($node->expr);
            [$variables, $arrow] = \array_pop($this->scopes) ?? [[], false];

            $variables[$node->var->name] = $factory;
            $this->scopes[] = [$variables, $arrow];
        } elseif ((($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $this->isFactory($node->var))
            || ($node instanceof Expr\StaticCall && $node->class instanceof Name && $this->isFacade($node->class))
        ) {
            $this->record($node);
        }

        return null;
    }

    /** @psalm-external-mutation-free */
    #[\Override]
    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike) {
            \array_pop($this->classes);
        } elseif ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            \array_pop($this->scopes);
        }

        return null;
    }

    private function record(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): void
    {
        $method = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

        // `View::composer(...)` only builds a closure; a call through it is not seen either way.
        if (!\in_array($method, ['composer', 'creator', 'composers'], true) || $call->isFirstClassCallable() || $this->ranAtBoot()) {
            return;
        }

        $parameter = $method === 'composers' ? 'composers' : 'views';
        $argument = null;

        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                $this->unknown = true;

                return;
            }

            if ($arg->name instanceof Identifier ? $arg->name->name === $parameter : $position === 0) {
                $argument = $arg->value;

                break;
            }
        }

        // Without it PHP throws before anything is registered.
        if (!$argument instanceof Expr) {
            return;
        }

        $views = [];

        if ($method === 'composers' && $argument instanceof Expr\Array_) {
            foreach ($argument->items as $item) {
                $views[] = $item instanceof Expr\ArrayItem && !$item->unpack ? $this->viewNames($item->value) : null;
            }
        } else {
            $views[] = $this->viewNames($argument);
        }

        foreach ($views as $names) {
            if ($names === null) {
                $this->unknown = true;

                return;
            }

            // The dispatcher keys a listener by the name `Factory::addViewEvent()` normalizes.
            foreach ($names as $name) {
                $this->patterns[] = ViewName::normalize($name);
            }
        }
    }

    /**
     * A call straight in a method of a provider the app loaded, of a parent class of one, or of a trait
     * one uses; in a closure there, it runs later.
     */
    private function ranAtBoot(): bool
    {
        $class = \end($this->classes);

        if ($class === false || $class[0] === '' || \count($this->scopes) !== 2) {
            return false;
        }

        foreach ($this->bootProviders as $provider) {
            if (\strtolower(\ltrim($provider, '\\')) === $class[0]) {
                return true;
            }

            $parents = \class_exists($provider) ? \class_parents($provider) : false;

            if (\is_array($parents) && isset(\array_change_key_case([...$parents, ...\class_uses_recursive($provider)])[$class[0]])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null
     *
     * @psalm-mutation-free
     */
    private function viewNames(Expr $expr): ?array
    {
        if ($expr instanceof String_) {
            return [$expr->value];
        }

        if (!$expr instanceof Expr\Array_) {
            return null;
        }

        $names = [];

        foreach ($expr->items as $item) {
            if (!$item instanceof Expr\ArrayItem || $item->unpack || !$item->value instanceof String_) {
                return null;
            }

            $names[] = $item->value->value;
        }

        return $names;
    }

    private function isFactory(Expr $expr): bool
    {
        if ($expr instanceof Expr\Variable) {
            return \is_string($expr->name) && $this->isFactoryVariable($expr->name);
        }

        if ($expr instanceof Expr\PropertyFetch) {
            $class = \end($this->classes);

            return $class !== false && $expr->var instanceof Expr\Variable && $expr->var->name === 'this'
                && $expr->name instanceof Identifier && isset($class[1][$expr->name->name]);
        }

        if (!$expr instanceof Expr\FuncCall || !$expr->name instanceof Name || $expr->isFirstClassCallable()) {
            return false;
        }

        $function = $this->functionName($expr->name);
        $args = $expr->getArgs();

        if ($function === 'view') {
            return $args === [];
        }

        if (($function !== 'app' && $function !== 'resolve') || \count($args) !== 1) {
            return false;
        }

        $abstract = $args[0]->value;

        return match (true) {
            $abstract instanceof String_ => \in_array($abstract->value, self::CONTAINER_KEYS, true),
            $abstract instanceof Expr\ClassConstFetch => $abstract->class instanceof Name && $abstract->name instanceof Identifier
                && $abstract->name->toLowerString() === 'class' && \in_array($this->className($abstract->class), self::FACTORIES, true),
            default => false,
        };
    }

    /**
     * Looked up through enclosing arrow functions, which see their parent's variables.
     *
     * @psalm-mutation-free
     */
    private function isFactoryVariable(string $name): bool
    {
        for ($index = \count($this->scopes) - 1; $index >= 0; --$index) {
            [$variables, $arrow] = $this->scopes[$index];

            if (isset($variables[$name])) {
                return $variables[$name];
            }

            if (!$arrow) {
                return false;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function factoryProperties(Stmt\ClassLike $class): array
    {
        $properties = [];

        foreach ($class->getProperties() as $property) {
            if ($this->isFactoryType($property->type)) {
                foreach ($property->props as $item) {
                    $properties[$item->name->name] = true;
                }
            }
        }

        foreach ($class->getMethod('__construct')?->params ?? [] as $param) {
            if ($param->isPromoted() && $param->var instanceof Expr\Variable && \is_string($param->var->name) && $this->isFactoryType($param->type)) {
                $properties[$param->var->name] = true;
            }
        }

        return $properties;
    }

    private function isFactoryType(?Node $type): bool
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        return $type instanceof Name && \in_array($this->className($type), self::FACTORIES, true);
    }

    private function isFacade(Name $name): bool
    {
        $class = $this->className($name);

        return $class === 'illuminate\\support\\facades\\view' || ($class === 'view' && $this->rootViewIsFacade);
    }

    /**
     * Resolved here rather than read off Psalm's `resolvedName`, which Psalm sets on method and closure
     * signatures but not on function, arrow function or property types.
     */
    private function className(Name $name): string
    {
        if ($name->isFullyQualified()) {
            return $name->toLowerString();
        }

        // `namespace\Foo` ignores imports.
        if ($name->isRelative()) {
            return \strtolower($this->namespace . $name->toString());
        }

        $alias = $this->uses[\strtolower($name->getFirst())] ?? null;
        $rest = $name->slice(1);

        return \strtolower($alias === null ? $this->namespace . $name->toString() : $alias . ($rest instanceof Name ? '\\' . $rest->toString() : ''));
    }

    /** An unqualified function falls back to the global one; Psalm resolves `use function` imports. */
    private function functionName(Name $name): string
    {
        /** @psalm-var mixed $resolved */
        $resolved = $name->getAttribute('resolvedName');

        return \strtolower(\ltrim(\is_string($resolved) ? $resolved : $name->toString(), '\\'));
    }
}
