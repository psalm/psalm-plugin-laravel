<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;

/**
 * Narrows return types of validation methods based on declared rules:
 *
 * - FormRequest::validated()  → array shape or single field type from rules()
 * - FormRequest::safe([...])  → partial array shape for specified keys
 * - Request::validate([...])  → array shape from inline rules argument
 *
 * Architecture follows {@see \Psalm\LaravelPlugin\Handlers\Console\CommandArgumentHandler}.
 */
final class ValidatedTypeHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            \Illuminate\Foundation\Http\FormRequest::class,
            \Illuminate\Http\Request::class,
        ];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        return match ($event->getMethodNameLowercase()) {
            'validated' => self::resolveValidated($event),
            'safe' => self::resolveSafe($event),
            'validate' => self::resolveInlineValidate($event),
            default => null,
        };
    }

    /**
     * FormRequest::validated() → full array shape or single field type.
     */
    private static function resolveValidated(MethodReturnTypeProviderEvent $event): ?Union
    {
        $rules = self::getRulesForCalledClass($event);

        if ($rules === null) {
            return null;
        }

        $callArgs = $event->getCallArgs();

        // validated() with no args → full array shape
        if ($callArgs === []) {
            return self::buildArrayShape($rules);
        }

        // validated('field') with literal string key → single field type
        return self::resolveFieldType($rules, $callArgs, $event);
    }

    /**
     * FormRequest::safe(['key1', 'key2']) → partial array shape for specified keys.
     *
     * safe() without args falls through to the stub return type (ValidatedInput|array).
     */
    private static function resolveSafe(MethodReturnTypeProviderEvent $event): ?Union
    {
        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null; // Fall through to stub — returns ValidatedInput
        }

        $rules = self::getRulesForCalledClass($event);

        if ($rules === null) {
            return null;
        }

        // safe(['key1', 'key2']) → extract literal string keys from the array argument
        $argType = $event->getSource()->getNodeTypeProvider()->getType($callArgs[0]->value);

        if (!$argType instanceof Union) {
            return null;
        }

        $keys = [];

        foreach ($argType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TKeyedArray) {
                foreach ($atomic->properties as $property) {
                    foreach ($property->getAtomicTypes() as $keyAtomic) {
                        if ($keyAtomic instanceof \Psalm\Type\Atomic\TLiteralString) {
                            $keys[] = $keyAtomic->value;
                        }
                    }
                }
            }
        }

        if ($keys === []) {
            return null;
        }

        // Build partial shape containing only the requested keys
        $filtered = [];

        foreach ($keys as $key) {
            if (isset($rules[$key])) {
                $filtered[$key] = $rules[$key];
            }
        }

        return self::buildArrayShape($filtered);
    }

    /**
     * Request::validate(['field' => 'rules']) → array shape from inline rules.
     */
    private static function resolveInlineValidate(MethodReturnTypeProviderEvent $event): ?Union
    {
        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null;
        }

        $rules = ValidationRuleAnalyzer::getRulesFromValidateArgs($callArgs);

        if ($rules === null) {
            return null;
        }

        return self::buildArrayShape($rules);
    }

    /**
     * Resolve rules for the concrete FormRequest subclass being analyzed.
     *
     * @return array<string, ResolvedRule>|null
     */
    private static function getRulesForCalledClass(MethodReturnTypeProviderEvent $event): ?array
    {
        /** @var class-string|null $calledClass */
        $calledClass = $event->getCalledFqClasslikeName();

        if ($calledClass === null) {
            return null;
        }

        return ValidationRuleAnalyzer::getRulesForFormRequest($calledClass);
    }

    /**
     * Resolve type for a single field by literal string key.
     *
     * @param array<string, ResolvedRule> $rules
     * @param list<\PhpParser\Node\Arg> $callArgs
     */
    private static function resolveFieldType(
        array $rules,
        array $callArgs,
        MethodReturnTypeProviderEvent $event,
    ): ?Union {
        $nodeTypeProvider = $event->getSource()->getNodeTypeProvider();
        $firstArgType = $nodeTypeProvider->getType($callArgs[0]->value);

        if ($firstArgType instanceof Union && $firstArgType->isSingleStringLiteral()) {
            $key = $firstArgType->getSingleStringLiteral()->value;

            if (isset($rules[$key])) {
                $fieldType = $rules[$key]->type;

                // If a default value is provided — validated($key, $default) —
                // the return type can be either the validated rule type or
                // the type of the default expression.
                if (isset($callArgs[1])) {
                    $defaultType = $nodeTypeProvider->getType($callArgs[1]->value);

                    if ($defaultType instanceof Union) {
                        $fieldType = \Psalm\Type::combineUnionTypes($fieldType, $defaultType);
                    }
                }

                return $fieldType;
            }
        }

        return null;
    }

    /**
     * Build a TKeyedArray shape from resolved rules.
     *
     * @param array<string, ResolvedRule> $rules
     * @psalm-mutation-free
     */
    private static function buildArrayShape(array $rules): ?Union
    {
        if ($rules === []) {
            return null;
        }

        /** @var non-empty-array<string, Union> $properties */
        $properties = [];

        foreach ($rules as $field => $resolvedRule) {
            $fieldType = $resolvedRule->type;

            if ($resolvedRule->sometimes) {
                $fieldType = $fieldType->setPossiblyUndefined(true);
            }

            $properties[$field] = $fieldType;
        }

        return new Union([TKeyedArray::make($properties)]);
    }
}
