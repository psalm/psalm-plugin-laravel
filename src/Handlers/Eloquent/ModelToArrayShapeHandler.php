<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type\Union;

/**
 * Psalm event adapter for {@see HasAttributes::attributesToArray()} / {@see Model::toArray()}, which
 * otherwise return Laravel's loose `array<string, mixed>`. Wires the hook only — method-name gate,
 * override bail, warmed {@see ModelMetadata} lookup — then delegates the shape to
 * {@see ModelSerializationShapeBuilder}. Registered per concrete Model class by
 * {@see ModelRegistrationHandler} (Psalm's provider lookup is exact-class).
 *
 * Experimental — the `getReturnType()` closure registration in {@see ModelRegistrationHandler}
 * is gated behind `<experimental><feature name="modelToArrayShape" /></experimental>`
 * (`Psalm\LaravelPlugin\Config\ExperimentalFeature::ModelToArrayShape`, see docs/config.md);
 * off by default. This class itself carries no gate — it is inert unless registered.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/923
 * @internal
 */
final class ModelToArrayShapeHandler
{
    private const ATTRIBUTES_TO_ARRAY = 'attributestoarray';

    private const TO_ARRAY = 'toarray';

    public static function getReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();
        if ($method !== self::ATTRIBUTES_TO_ARRAY && $method !== self::TO_ARRAY) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();

        /** @var class-string<Model> $modelClass */
        $modelClass = $event->getFqClasslikeName();

        // Bail on an overridden serializer (may return a different shape). toArray() delegates to
        // attributesToArray(), so a toArray() shape needs BOTH to be the framework's.
        if (!self::isFrameworkSerializer($codebase, $modelClass, self::ATTRIBUTES_TO_ARRAY)) {
            return null;
        }

        if ($method === self::TO_ARRAY && !self::isFrameworkSerializer($codebase, $modelClass, self::TO_ARRAY)) {
            return null;
        }

        $metadata = ModelMetadataRegistry::for($modelClass);
        if (!$metadata instanceof ModelMetadata) {
            return null;
        }

        return ModelSerializationShapeBuilder::build($codebase, $modelClass, $metadata);
    }

    /**
     * True when `$modelClass::$method` still resolves to Laravel's own implementation
     * (`HasAttributes::attributesToArray` / `Model::toArray`), i.e. no override on the concrete
     * class or an intervening trait.
     *
     * @param lowercase-string $method
     * @psalm-mutation-free
     */
    private static function isFrameworkSerializer(Codebase $codebase, string $modelClass, string $method): bool
    {
        $declaring = $codebase->methods->getDeclaringMethodId(new MethodIdentifier($modelClass, $method));
        if (!$declaring instanceof MethodIdentifier) {
            return false;
        }

        $declaringClass = \strtolower($declaring->fq_class_name);

        return $declaringClass === \strtolower(HasAttributes::class)
            || $declaringClass === \strtolower(Model::class);
    }
}
