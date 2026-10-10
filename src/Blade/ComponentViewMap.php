<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\View\Component;
use Illuminate\View\ViewName as LaravelViewName;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psalm\LaravelPlugin\Handlers\Views\ComponentRenderData;

/**
 * Pairs a literal view name with the class component whose `render()` renders it, so that view's
 * shadow can declare render()'s data with the types Psalm infers for it in class scope (#1804).
 *
 * Only `return view('lit', [...])`, `view()->make('lit', [...])` and `View::make('lit', [...])` as
 * render()'s single statement qualify, with a literal array whose values read nothing but `$this`,
 * constants, literals, and calls: the array is copied into the shadow verbatim, where render()'s
 * locals do not exist. Anything else declines, and the view keeps its untyped prelude.
 *
 * A key `Component::data()` can also supply is never declared. On a `<x-...>`/`@component`/
 * `Blade::renderComponent()` render, `data()` and the slots are merged OVER render()'s array, on a
 * manual `$component->render()` they are not, and which path a view takes is not knowable here.
 *
 * @internal
 */
final class ComponentViewMap
{
    /** Functions that read or write the calling scope's variables by name, which the copy cannot keep. */
    private const SCOPE_FUNCTIONS = ['compact', 'extract', 'get_defined_vars', 'func_get_args', 'func_get_arg', 'func_num_args'];

    /**
     * @param array<string, array{class: string, keys: list<string>, scope: string}> $views view name => component view
     *
     * @psalm-mutation-free
     */
    private function __construct(private readonly array $views) {}

    /**
     * @param list<string> $phpFiles the project files Psalm analyzes; only a file naming a
     *                               `render` function and a view factory call is parsed
     */
    public static function build(array $phpFiles): self
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        /** @var array<string, list<array{class: string, keys: list<string>, scope: string}|null>> $candidates */
        $candidates = [];

        foreach ($phpFiles as $file) {
            $code = @\file_get_contents($file);

            // Parsing dominates the cost, so a file must name both halves of the shape first.
            if ($code === false || \stripos($code, 'function render') === false
                || (\stripos($code, 'view(') === false && \stripos($code, 'View::make(') === false)
            ) {
                continue;
            }

            try {
                $ast = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => true])))
                    ->traverse($parser->parse($code) ?? []);
            } catch (\Throwable) {
                continue;
            }

            foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
                $statements = $class->namespacedName instanceof Name ? $class->getMethod('render')?->stmts : null;

                if ($statements === null || !$class->namespacedName instanceof Name) {
                    continue;
                }

                $call = self::renderCall($statements);

                if ($call !== null) {
                    $candidates[LaravelViewName::normalize($call[0])][] = self::componentView($class->namespacedName->toString(), $call[1]);

                    continue;
                }

                // Every class naming the view is kept, declined or not: a second renderer passes
                // other data, so the view is no longer one class's to type.
                foreach ($finder->find($statements, static fn(Node $node): bool => self::isFactoryCall($node)) as $factoryCall) {
                    $view = $factoryCall instanceof Expr\CallLike && !$factoryCall->isFirstClassCallable() ? ($factoryCall->getArgs()[0] ?? null)?->value : null;

                    if ($view instanceof Node\Scalar\String_) {
                        $candidates[LaravelViewName::normalize($view->value)][] = null;
                    }
                }
            }
        }

        $views = [];

        foreach ($candidates as $view => $renderers) {
            if (\count($renderers) === 1 && $renderers[0] !== null) {
                $views[$view] = $renderers[0];
            }
        }

        return new self($views);
    }

    /**
     * @return array{class: string, keys: list<string>, scope: string}|null
     *
     * @psalm-mutation-free
     */
    public function get(string $viewName): ?array
    {
        return $this->views[$viewName] ?? null;
    }

    /**
     * The view name and data array of a render() that is a single `return` of a view factory call.
     *
     * @param array<Node\Stmt> $statements render()'s body
     *
     * @return array{0: string, 1: Expr\Array_}|null
     */
    private static function renderCall(array $statements): ?array
    {
        if (\count($statements) !== 1 || !$statements[0] instanceof Node\Stmt\Return_) {
            return null;
        }

        $call = $statements[0]->expr;

        if (!$call instanceof Expr\CallLike || !self::isFactoryCall($call) || $call->isFirstClassCallable() || \count($call->getArgs()) !== 2) {
            return null;
        }

        [$view, $data] = $call->getArgs();

        if ($view->unpack || $data->unpack || $view->name instanceof Node\Identifier || $data->name instanceof Node\Identifier
            || !$view->value instanceof Node\Scalar\String_ || !$data->value instanceof Expr\Array_
        ) {
            return null;
        }

        return [$view->value->value, $data->value];
    }

    /** `view(...)`, `view()->make(...)`, or `View::make(...)`. */
    private static function isFactoryCall(Node $node): bool
    {
        if ($node instanceof Expr\FuncCall) {
            return self::isViewFunction($node);
        }

        if ($node instanceof Expr\MethodCall) {
            return $node->var instanceof Expr\FuncCall
                && self::isViewFunction($node->var)
                && $node->var->args === []
                && $node->name instanceof Node\Identifier
                && $node->name->toLowerString() === 'make';
        }

        return $node instanceof Expr\StaticCall
            && $node->class instanceof Name
            && \in_array($node->class->toString(), ['Illuminate\Support\Facades\View', 'View'], true)
            && $node->name instanceof Node\Identifier
            && $node->name->toLowerString() === 'make';
    }

    private static function isViewFunction(Expr\FuncCall $call): bool
    {
        return $call->name instanceof Name && $call->name->toLowerString() === 'view';
    }

    /**
     * @return array{class: string, keys: list<string>, scope: string}|null
     */
    private static function componentView(string $className, Expr\Array_ $data): ?array
    {
        $keys = [];

        foreach ($data->items as $item) {
            if ($item === null || $item->byRef || $item->unpack || !$item->key instanceof Node\Scalar\String_) {
                return null;
            }

            $keys[] = $item->key->value;
        }

        $outOfScope = (new NodeFinder())->findFirst($data, static fn(Node $node): bool => ($node instanceof Expr\Variable && $node->name !== 'this')
            || $node instanceof Node\Scalar\MagicConst
            || $node instanceof Expr\Yield_
            || $node instanceof Expr\YieldFrom
            || $node instanceof Expr\Include_
            || $node instanceof Expr\Eval_
            || $node instanceof Expr\Exit_
            || ($node instanceof Expr\FuncCall && $node->name instanceof Name
                && \in_array($node->name->toLowerString(), self::SCOPE_FUNCTIONS, true)));

        if ($outOfScope instanceof Node) {
            return null;
        }

        $exposed = self::dataKeys($className);

        if ($exposed === null) {
            return null;
        }

        $declared = [];

        foreach (\array_unique($keys) as $key) {
            if (\preg_match('/^' . ContractParser::IDENTIFIER . '$/', $key) === 1
                && $key !== 'this'
                && !\str_starts_with($key, '__')
                && !isset(PreludeBuilder::AMBIENT_TYPES[$key])
                && !isset($exposed[$key])
            ) {
                $declared[] = $key;
            }
        }

        if ($declared === []) {
            return null;
        }

        (new NodeTraverser(new class extends NodeVisitorAbstract {
            /**
             * The shadow has no namespace, so an unqualified call or constant that resolved to the
             * render() file's namespace must be spelled out; one that fell back to global needs nothing.
             */
            #[\Override]
            public function enterNode(Node $node): null
            {
                if (!$node instanceof Expr\FuncCall && !$node instanceof Expr\ConstFetch || !$node->name instanceof Name) {
                    return null;
                }

                /** @psalm-var FullyQualified|null $namespaced */
                $namespaced = $node->name->getAttribute('namespacedName');

                if ($namespaced instanceof FullyQualified
                    && ($node instanceof Expr\FuncCall ? \function_exists($namespaced->toString()) : \defined($namespaced->toString()))
                ) {
                    $node->name = $namespaced;
                }

                return null;
            }
        }))->traverse([$data]);

        $targets = \implode(', ', \array_map(
            static fn(string $key): string => \var_export($key, true) . ' => $' . $key,
            $declared,
        ));

        // A CLASS template on the receiver, not a method template: Psalm binds `$this` from
        // `@param-closure-this` only through the former (stubs/blade/ComponentScope.phpstub).
        $scope = "/** @var \\Psalm\\LaravelPlugin\\Blade\\ComponentScope<\\{$className}> \$__laravelComponentScope */\n"
            . "[{$targets}] = (\$__laravelComponentScope->bind(function () { return "
            . (new Standard())->prettyPrintExpr($data) . "; }))();\n";

        return ['class' => $className, 'keys' => $declared, 'scope' => $scope];
    }

    /**
     * The names `Component::data()` (and the slots) can merge over render()'s array, by reflection on
     * the booted class: `ComponentRenderData`'s exposure rule, which works on Psalm storage that does
     * not exist yet at boot. Null declines: not an autoloadable concrete component, or a `data()`
     * override that can add any key.
     *
     * @return array<string, true>|null
     */
    private static function dataKeys(string $className): ?array
    {
        try {
            if (!\class_exists($className) || !\is_subclass_of($className, Component::class)) {
                return null;
            }

            $class = new \ReflectionClass($className);

            if ($class->isAbstract() || $class->getMethod('data')->getDeclaringClass()->getName() !== Component::class) {
                return null;
            }

            $exposed = ['slot' => true];
            $members = [
                ...\array_filter($class->getProperties(\ReflectionProperty::IS_PUBLIC), static fn(\ReflectionProperty $property): bool => !$property->isStatic()),
                ...$class->getMethods(\ReflectionMethod::IS_PUBLIC),
            ];

            foreach ($members as $member) {
                if (!ComponentRenderData::ignored($member->getName())) {
                    $exposed[$member->getName()] = true;
                }
            }

            return $exposed;
        } catch (\Throwable) {
            // A class whose parent or interface cannot be loaded throws on autoload.
            return null;
        }
    }
}
