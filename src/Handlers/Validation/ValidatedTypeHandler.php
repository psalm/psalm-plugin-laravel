<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Narrows return types of validation methods based on the declared rules:
 *
 * @see \Illuminate\Foundation\Http\FormRequest::validated()
 * @see \Illuminate\Foundation\Http\FormRequest::safe()
 * @see \Illuminate\Http\Request::validate()  (via ValidatesRequests trait / @method annotation)
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
        $methodName = $event->getMethodNameLowercase();

        if ($methodName === 'validated') {
            return self::resolveValidated($event);
        }

        if ($methodName === 'safe') {
            return self::resolveSafe($event);
        }

        return null;
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

    /** @psalm-mutation-free */
    private static function resolveSafe(MethodReturnTypeProviderEvent $event): ?Union
    {
        $callArgs = $event->getCallArgs();

        // safe() without args returns ValidatedInput — return it for better method chaining
        if ($callArgs === []) {
            return new Union([
                new TNamedObject(\Illuminate\Support\ValidatedInput::class),
            ]);
        }

        // safe(['key1', 'key2']) returns array — could narrow to partial shape
        // For now, fall through to stub default
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
