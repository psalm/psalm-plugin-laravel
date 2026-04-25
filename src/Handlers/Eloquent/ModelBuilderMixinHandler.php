<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\LaravelPlugin\Handlers\Magic\ReturnTypeResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Intercepts Model's @mixin Builder<static> fluent calls after Builder stubs use $this/static.
 *
 * Psalm binds $this/static in mixin-reached methods to the mixin host, so without this
 * Customer::where() and (new Customer())->where() would be typed as Customer&static.
 *
 * If another non-Eloquent forwarding domain is added later, this logic could move behind a
 * ForwardingRule callback instead. Keeping it here is the smaller step while Eloquent is the
 * only model-builder host with this behavior.
 */
final class ModelBuilderMixinHandler implements MethodReturnTypeProviderInterface
{
    private const MODEL_FQN_LOWER = 'illuminate\\database\\eloquent\\model';

    /**
     * Cache: class name → whether it is an Eloquent model.
     *
     * @var array<lowercase-string, bool>
     */
    private static array $modelClassCache = [];

    /** @psalm-external-mutation-free */
    public static function init(): void
    {
        self::$modelClassCache = [];
    }

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Builder::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $codebase = $source->getCodebase();
        $methodName = $event->getMethodNameLowercase();

        if (!ReturnTypeResolver::targetClassMethodReturnsSelf($codebase, Builder::class, $methodName)) {
            return null;
        }

        $stmt = $event->getStmt();
        $callerType = $stmt instanceof MethodCall
            ? $source->getNodeTypeProvider()->getType($stmt->var)
            : null;

        $modelClass = self::extractModelClassFromMixinCaller($event, $codebase, $callerType);

        if ($modelClass === null) {
            return null;
        }

        return new Union([ModelMethodHandler::resolvedBuilderTypeFor($modelClass, $codebase)]);
    }

    /**
     * @return class-string<Model>|null
     * @psalm-external-mutation-free
     */
    private static function extractModelClassFromMixinCaller(
        MethodReturnTypeProviderEvent $event,
        Codebase $codebase,
        ?Union $callerType,
    ): ?string {
        $stmt = $event->getStmt();

        if ($stmt instanceof StaticCall) {
            $calledClass = $event->getCalledFqClasslikeName();

            if (\is_string($calledClass) && self::isModelClass($codebase, $calledClass)) {
                return $calledClass;
            }

            return null;
        }

        if (!$callerType instanceof Union) {
            return null;
        }

        foreach ($callerType->getAtomicTypes() as $atomicType) {
            if ($atomicType instanceof TNamedObject && self::isModelClass($codebase, $atomicType->value)) {
                return $atomicType->value;
            }
        }

        return null;
    }

    /**
     * @psalm-assert-if-true class-string<Model> $className
     * @psalm-external-mutation-free
     */
    private static function isModelClass(Codebase $codebase, string $className): bool
    {
        $classNameLower = \strtolower($className);

        if (\array_key_exists($classNameLower, self::$modelClassCache)) {
            return self::$modelClassCache[$classNameLower];
        }

        if ($classNameLower === self::MODEL_FQN_LOWER) {
            return self::$modelClassCache[$classNameLower] = true;
        }

        try {
            return self::$modelClassCache[$classNameLower] = $codebase->classOrInterfaceExists($className)
                && $codebase->classExtends($className, Model::class);
        } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
            return self::$modelClassCache[$classNameLower] = false;
        }
    }
}
