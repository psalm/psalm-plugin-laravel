<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata;
use Psalm\LaravelPlugin\Providers\ModelMetadataRegistry;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type\Union;

/**
 * Psalm event adapter for a model's serialization methods,
 * {@see HasAttributes::attributesToArray()} and {@see Model::toArray()}.
 *
 * Without this handler both return Laravel's loose `array<string, mixed>`. With it the inferred type
 * names each serialized key, e.g.
 *
 *   array{id?: int, name?: string, created_at?: string|null, full_name?: string, ...<string, mixed>}
 *
 * This class only wires the Psalm hook: it gates on the method name, bails when the model overrides
 * the serializer, resolves the warmed {@see ModelMetadata}, and delegates the actual shape (Laravel's
 * serialization order + per-attribute serialized types) to {@see ModelSerializationShapeBuilder}.
 *
 * Registered per concrete Model class by {@see ModelRegistrationHandler} because Psalm's provider
 * lookup uses exact class-name matching.
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

        // Bail when the model (or a trait) overrides the serializer — an override may return a shape
        // different from Laravel's. Model::toArray() delegates to attributesToArray(), so a toArray()
        // shape is valid only when BOTH are the framework's: an attributesToArray()-only override
        // still changes toArray()'s output. Mirrors ModelAttributeSubsetHandler's override bail.
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
