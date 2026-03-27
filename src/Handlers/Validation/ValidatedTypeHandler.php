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
 * Narrows return types of validation methods based on declared rules:
 *
 * - FormRequest::validated()         → array shape or single field type from rules()
 * - FormRequest::safe([...])         → partial array shape for specified keys
 * - Request::validate([...])         → array shape from inline rules argument
 * - ValidatedInput::input('field')   → single field type (via generic TRequest parameter)
 *
 * ValidatedInput is generic: ValidatedInput<TRequest of FormRequest>. When safe() returns
 * ValidatedInput<static>, the template parameter carries the concrete FormRequest class,
 * enabling type narrowing on ValidatedInput accessor methods.
 *
 * Known limitation: when this handler provides a return type, Psalm skips the stub's
 * @psalm-taint-source annotation for variable assignments. This means taint is lost
 * when validated data is assigned to a variable before reaching a sink.
 * Per project principle "silence over false positives", this is acceptable.
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
            \Illuminate\Support\ValidatedInput::class,
        ];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        // ValidatedInput methods — resolve via generic TRequest parameter.
        // Use getFqClasslikeName() (declaring class) instead of getCalledFqClasslikeName()
        // because input()/str()/etc. are inherited from the InteractsWithData trait —
        // getCalledFqClasslikeName() may be null on the first dispatch.
        if ($event->getFqClasslikeName() === \Illuminate\Support\ValidatedInput::class) {
            return self::resolveValidatedInputMethod($event);
        }

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
     * safe() without args falls through to the stub return type (ValidatedInput<static>|array).
     */
    private static function resolveSafe(MethodReturnTypeProviderEvent $event): ?Union
    {
        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null; // Fall through to stub — returns ValidatedInput<static>
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
     * ValidatedInput::input('field'), ::str('field'), etc. → resolve via TRequest template.
     *
     * When safe() returns ValidatedInput<StoreUserRequest>, Psalm carries the template
     * parameter. We extract it here to look up the FormRequest's rules.
     */
    private static function resolveValidatedInputMethod(MethodReturnTypeProviderEvent $event): ?Union
    {
        $methodName = $event->getMethodNameLowercase();

        // Only narrow methods that take a field key as first argument
        if (!\in_array($methodName, ['input', 'str', 'string', 'collect'], true)) {
            return null;
        }

        $callArgs = $event->getCallArgs();

        if ($callArgs === []) {
            return null;
        }

        // Extract the FormRequest class from the generic TRequest parameter
        $templateParams = $event->getTemplateTypeParameters();

        if ($templateParams === null || !isset($templateParams[0])) {
            return null;
        }

        $formRequestClass = self::extractClassFromUnion($templateParams[0]);

        if ($formRequestClass === null) {
            return null;
        }

        $rules = ValidationRuleAnalyzer::getRulesForFormRequest($formRequestClass);

        if ($rules === null) {
            return null;
        }

        return self::resolveFieldType($rules, $callArgs, $event);
    }

    /**
     * Extract a class-string from a Union type (e.g., from a template parameter).
     *
     * @return class-string|null
     *
     * @psalm-mutation-free
     */
    private static function extractClassFromUnion(Union $union): ?string
    {
        foreach ($union->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject) {
                /** @var class-string */
                return $atomic->value;
            }
        }

        return null;
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
                        $fieldType = Type::combineUnionTypes($fieldType, $defaultType);
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

            // Fields without a presence rule (required, present, accepted, etc.)
            // may be absent from validated() output when not provided in the request.
            if (!$resolvedRule->required) {
                $fieldType = $fieldType->setPossiblyUndefined(true);
            }

            $properties[$field] = $fieldType;
        }

        return new Union([TKeyedArray::make($properties)]);
    }
}
