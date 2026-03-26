<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;

/**
 * Narrows return types of FormRequest::validated() based on declared validation rules.
 *
 * @see \Illuminate\Foundation\Http\FormRequest::validated()
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
        ];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'validated') {
            return null;
        }

        return self::resolveValidated($event);
    }

    private static function resolveValidated(MethodReturnTypeProviderEvent $event): ?Union
    {
        /** @var class-string|null $calledClass */
        $calledClass = $event->getCalledFqClasslikeName();

        if ($calledClass === null) {
            return null;
        }

        $rules = ValidationRuleAnalyzer::getRulesForFormRequest($calledClass);

        if ($rules === null) {
            return null;
        }

        $callArgs = $event->getCallArgs();

        // validated() with no args → full array shape
        if ($callArgs === []) {
            return self::buildArrayShape($rules);
        }

        // validated('field') with literal string key → single field type
        $firstArgType = $event->getSource()->getNodeTypeProvider()->getType($callArgs[0]->value);

        if ($firstArgType instanceof Union && $firstArgType->isSingleStringLiteral()) {
            $key = $firstArgType->getSingleStringLiteral()->value;

            if (isset($rules[$key])) {
                return $rules[$key]->type;
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
