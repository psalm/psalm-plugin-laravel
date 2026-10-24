<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Contracts\Container\Container;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Nop;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Carries taint from a class component's constructor data into the view it renders (#2033).
 *
 * `Component::data()` hands the view its public properties, but a prelude variable is a docblock-typed
 * script global with no data-flow node, so request input given to `<x-alert :message="request('m')" />`
 * never reached `{!! $message !!}`. Psalm's own flows are the bridge, and this class adds the two halves
 * it cannot see:
 *
 * Source side, in compiled shadows and PHP code alike: `Foo::resolve($data)` (what `<x-foo>` and
 * `@component(Foo::class, ...)` compile to) and `$container->make(Foo::class, $data)` both bind each data
 * key to the constructor PARAMETER of that name (`new static(...array_intersect_key($data, ...))`, the
 * container's named parameters). So each literal entry is connected to that parameter's argument node, and
 * the flow then runs through the constructor body as it does for `new Foo($input)`: `e($message)` or a
 * replaced value stays clean, a parameter stored under another property name still reaches it. A key that
 * is not a constructor parameter writes nothing.
 *
 * Sink side: runs on the sentinel of {@see ComponentViewSeedHandler}, for the property nodes of the
 * classes {@see ComponentViewRegistry} maps to the shadow's view, and MUST be registered after it, because
 * the seed replaces a variable's whole type and would drop the nodes attached here.
 *
 * The property node belongs to the class that declares or (for a trait) uses the property, not to a tag:
 * one tainted `<x-alert>` anywhere taints every read of `$message` in that class's view.
 *
 * @internal
 */
final class ComponentPropertyTaintBridge implements AfterExpressionAnalysisInterface, AfterStatementAnalysisInterface
{
    private const COMPONENT = 'illuminate\view\component';

    /** Laravel's own `make()`: a userland override may bind the parameters anywhere, or nowhere. */
    private const OWN_MAKE = [
        'illuminate\contracts\container\container' => true,
        'illuminate\container\container' => true,
        'illuminate\foundation\application' => true,
    ];

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        // The cheapest gate: `Foo::resolve($data)` and `$container->make(Foo::class, $data)`.
        $expr = self::dataCall($event->getExpr());

        if ($expr === null) {
            return null;
        }

        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();

        if ($expr->isFirstClassCallable() || !$codebase->tracksTaintFlow($source)) {
            return null;
        }

        $args = $expr->getArgs();

        // Positional and unspread only: a name or a spread moves the data off its slot.
        foreach ($args as $arg) {
            if ($arg->unpack || $arg->name instanceof Identifier) {
                return null;
            }
        }

        if ($expr instanceof Expr\StaticCall) {
            $classNode = $expr->class;
            $dataArg = $args[0] ?? null;
        } else {
            $classNode = ($args[0] ?? null)?->value;
            $dataArg = $args[1] ?? null;

            if (!self::isContainer($expr->var, $source)) {
                return null;
            }
        }

        if (!$dataArg instanceof Arg) {
            return null;
        }

        $class = self::componentOf($classNode, $codebase);
        $constructor = $class instanceof \Psalm\Storage\ClassLikeStorage ? self::constructorOf($codebase, $class) : null;

        if (!$class instanceof \Psalm\Storage\ClassLikeStorage || $constructor === null) {
            return null;
        }

        [$casedId, $storage] = $constructor;

        // The compiled `[…] + $attributes`: the tag's own attributes are the left operand.
        $data = $dataArg->value;

        while ($data instanceof Expr\BinaryOp\Plus) {
            $data = $data->left;
        }

        foreach ($data instanceof Expr\Array_ ? ViewCallChain::arrayEntries($data) : [] as [$key, $value]) {
            $type = $source->getNodeTypeProvider()->getType($value);
            $offset = self::parameterOffset($storage->params, $key);

            if ($offset === null || !$type instanceof Union || $type->parent_nodes === []) {
                continue;
            }

            $node = $codebase->getTaintNode($source, "\${$key} of component {$class->name}", new CodeLocation($source, $value), $type);
            $codebase->addTaintPath($source, $node, DataFlowNode::getForMethodArgument($casedId, $offset, $storage), 'arg');
        }

        return null;
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

        $context = $event->getContext();
        $location = new CodeLocation($source, $stmt);

        foreach (ComponentViewRegistry::propertyIdsFor($source->getFilePath()) as $name => $ids) {
            $type = $context->vars_in_scope['$' . $name] ?? null;

            // A variable the template never reads has nothing to carry the flow.
            if (!$type instanceof Union) {
                continue;
            }

            $node = $codebase->getTaintNode($source, "\${$name} (component property)", $location);

            foreach ($ids as $id) {
                $codebase->addTaintPath($source, Codebase::getTaintPropertyNode($id), $node);
            }

            $context->vars_in_scope['$' . $name] = $type->addParentNodes([$node->id => $node]);
        }

        return null;
    }

    /**
     * The `Component` subclass a `Foo` / `Foo::class` / `'Foo'` argument names, or null for anything
     * else: a dynamic class, `self`/`static`, or a class that is not a component (a look-alike with its
     * own `resolve()` hands its data to code this bridge knows nothing about).
     */
    private static function componentOf(Expr|Name|null $class, Codebase $codebase): ?ClassLikeStorage
    {
        $name = match (true) {
            $class instanceof Name => $class,
            $class instanceof Expr\ClassConstFetch && $class->class instanceof Name && self::isNamed($class->name, 'class') => $class->class,
            default => null,
        };

        if ($name instanceof Name) {
            /** @psalm-var ?string $resolved */
            $resolved = $name->getAttribute('resolvedName');
            $className = \is_string($resolved) ? $resolved : $name->toString();
        } elseif ($class instanceof String_) {
            $className = \ltrim($class->value, '\\');
        } else {
            return null;
        }

        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        return isset($storage->parent_classes[self::COMPONENT]) ? $storage : null;
    }

    /**
     * The constructor `new Foo(...)` runs, with the cased id Psalm keys its argument nodes on: the
     * declaring one, so an inherited constructor is the parent's.
     *
     * @return array{0: string, 1: \Psalm\Storage\MethodStorage}|null
     *
     * @psalm-capabilities read-props
     */
    private static function constructorOf(Codebase $codebase, ClassLikeStorage $class): ?array
    {
        $constructor = $class->declaring_method_ids['__construct'] ?? null;

        if (!$constructor instanceof MethodIdentifier || !$codebase->methods->hasStorage($constructor)) {
            return null;
        }

        return [$codebase->methods->getCasedMethodId($constructor), $codebase->methods->getStorage($constructor)];
    }

    /**
     * The declared index of the non-variadic parameter `$name`, which is what an argument node is keyed on.
     *
     * @param list<\Psalm\Storage\FunctionLikeParameter> $params
     * @psalm-mutation-free
     */
    private static function parameterOffset(array $params, string $name): ?int
    {
        foreach ($params as $offset => $param) {
            if ($param->name === $name) {
                return $param->is_variadic ? null : $offset;
            }
        }

        return null;
    }

    /** Laravel's own container, through a `make()` that is still Laravel's. */
    private static function isContainer(Expr $receiver, StatementsSource $source): bool
    {
        $type = $source->getNodeTypeProvider()->getType($receiver);

        if (!$type instanceof Union || !$type->isSingle()) {
            return false;
        }

        $atomic = $type->getSingleAtomic();
        $codebase = $source->getCodebase();

        if (!$atomic instanceof TNamedObject || !ClassLineage::isA($codebase, $atomic->value, Container::class)) {
            return false;
        }

        try {
            $make = $codebase->classlike_storage_provider->get(\strtolower($atomic->value))->declaring_method_ids['make'] ?? null;
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }

        return $make instanceof MethodIdentifier && isset(self::OWN_MAKE[\strtolower($make->fq_class_name)]);
    }

    private static function dataCall(Expr $expr): Expr\StaticCall|Expr\MethodCall|null
    {
        if ($expr instanceof Expr\StaticCall && self::isNamed($expr->name, 'resolve')) {
            return $expr;
        }

        return $expr instanceof Expr\MethodCall && self::isNamed($expr->name, 'make') ? $expr : null;
    }

    private static function isNamed(Expr|Identifier $name, string $lowercase): bool
    {
        return $name instanceof Identifier && $name->toLowerString() === $lowercase;
    }
}
