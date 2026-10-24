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
use Psalm\LaravelPlugin\Handlers\Views\ComponentRenderData;
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
 * Carries taint from a class component's public properties into the view it renders (#2033).
 *
 * `Component::data()` hands the view its public properties, but a prelude variable is a docblock-typed
 * script global with no data-flow node, so request input given to `<x-alert :message="request('m')" />`
 * never reached `{!! $message !!}`. Psalm's property nodes (`Alert::$message`) are the bridge: Psalm
 * already writes them for `new Alert($input)` and for `$this->text = $text`, and this class adds the two
 * halves it cannot see.
 *
 * Source side, in compiled shadows and PHP code alike: the literal-keyed data of `Foo::resolve($data)`
 * (what `<x-foo>` compiles to; the attribute bag appended with `+` is unknown and skipped) and of
 * `$container->make(Foo::class, $data)` is written to the property node of every key `Foo` exposes as a
 * public property. A key that is not a property of that name (a constructor parameter the class stores
 * under another name) is not followed.
 *
 * Sink side: runs on the sentinel of {@see ComponentViewSeedHandler}, for the properties of the classes
 * {@see ComponentViewRegistry} maps to the shadow's view, and MUST be registered after it, because the
 * seed replaces a variable's whole type and would drop the nodes attached here.
 *
 * The property node belongs to the class, not to a tag: one tainted `<x-alert>` anywhere taints every
 * read of `$message` in that class's view.
 *
 * @internal
 */
final class ComponentPropertyTaintBridge implements AfterExpressionAnalysisInterface, AfterStatementAnalysisInterface
{
    private const COMPONENT = 'illuminate\view\component';

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

        // `Foo::resolve($data)` and `$container->make(Foo::class, $data)`.
        if ($expr instanceof Expr\StaticCall) {
            $classNode = $expr->class instanceof Name ? $expr->class : null;
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

        if (!$class instanceof ClassLikeStorage) {
            return null;
        }

        $properties = ComponentRenderData::exposedPropertyIds($codebase, $class);

        if ($properties === []) {
            return null;
        }

        foreach (self::literalEntries($dataArg->value) as $key => $value) {
            $type = $source->getNodeTypeProvider()->getType($value);

            if (!isset($properties[$key]) || !$type instanceof Union || $type->parent_nodes === []) {
                continue;
            }

            $node = $codebase->getTaintNode($source, "\${$key} of component {$class->name}", new CodeLocation($source, $value), $type);
            $codebase->addTaintPath($source, $node, Codebase::getTaintPropertyNode($properties[$key]));
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

    /** A receiver that is, or implements, Laravel's container contract. */
    private static function isContainer(Expr $receiver, StatementsSource $source): bool
    {
        $type = $source->getNodeTypeProvider()->getType($receiver);

        if (!$type instanceof Union || !$type->isSingle()) {
            return false;
        }

        $atomic = $type->getSingleAtomic();

        return $atomic instanceof TNamedObject
            && ClassLineage::isA($source->getCodebase(), $atomic->value, Container::class);
    }

    /**
     * The literal-keyed entries of a data array, later keys winning; for the compiled `[…] + $attributes`
     * only the left operand, which is where the tag's own attributes are.
     *
     * @return array<string, Expr>
     *
     * @psalm-capabilities read-props
     */
    private static function literalEntries(Expr $data): array
    {
        while ($data instanceof Expr\BinaryOp\Plus) {
            $data = $data->left;
        }

        $entries = [];

        if (!$data instanceof Expr\Array_) {
            return $entries;
        }

        foreach ($data->items as $item) {
            if ($item !== null && $item->key instanceof String_ && !$item->unpack) {
                $entries[$item->key->value] = $item->value;
            }
        }

        return $entries;
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
