<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Handles scope method discovery on Eloquent Builder.
 *
 * When a method call on Builder doesn't match a real method, checks the model for:
 * 1. Legacy scopeXxx() methods (e.g., scopeActive → active())
 * 2. Methods with #[Scope] attribute (e.g., #[Scope] active() → active())
 * 3. @method PHPDoc scopes are handled natively by Psalm
 *
 * @internal
 */
final class BuilderScopeHandler implements MethodReturnTypeProviderInterface
{
    /** @var array<string, bool> */
    private static array $scopeCache = [];

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
        $methodName = $event->getMethodNameLowercase();
        $codebase = $event->getSource()->getCodebase();
        $templateTypeParameters = $event->getTemplateTypeParameters();

        $modelClass = self::resolveModelClass($templateTypeParameters);
        if ($modelClass === null) {
            return null;
        }

        if (self::hasScopeMethod($codebase, $modelClass, $methodName)) {
            return new Union([
                new TGenericObject(Builder::class, [
                    new Union([new TNamedObject($modelClass)]),
                ]),
            ]);
        }

        return null;
    }

    /**
     * @param non-empty-list<Union>|null $templateTypeParameters
     * @psalm-mutation-free
     */
    private static function resolveModelClass(?array $templateTypeParameters): ?string
    {
        if ($templateTypeParameters === null) {
            return null;
        }

        foreach ($templateTypeParameters as $type) {
            foreach ($type->getAtomicTypes() as $atomic) {
                if ($atomic instanceof TNamedObject && \is_a($atomic->value, Model::class, true)) {
                    return $atomic->value;
                }
            }
        }

        return null;
    }

    /**
     * Check if the model has a scope for the given method name.
     */
    private static function hasScopeMethod(\Psalm\Codebase $codebase, string $modelClass, string $methodName): bool
    {
        $key = $modelClass . '::' . $methodName;

        if (\array_key_exists($key, self::$scopeCache)) {
            return self::$scopeCache[$key];
        }

        // Check legacy scope prefix: scopeActive → active
        $legacyScopeMethod = $modelClass . '::scope' . \ucfirst($methodName);
        if ($codebase->methodExists($legacyScopeMethod)) {
            self::$scopeCache[$key] = true;
            return true;
        }

        // Check #[Scope] attribute: method name matches directly
        $directMethod = $modelClass . '::' . $methodName;
        if ($codebase->methodExists($directMethod)) {
            try {
                $reflection = new \ReflectionMethod($modelClass, $methodName);
                $attributes = $reflection->getAttributes(Scope::class);
                if ($attributes !== []) {
                    self::$scopeCache[$key] = true;
                    return true;
                }
            } catch (\ReflectionException) {
                // Method doesn't exist at runtime
            }
        }

        self::$scopeCache[$key] = false;
        return false;
    }
}
