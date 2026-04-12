<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
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
 * Upstream: https://github.com/vimeo/psalm/issues/11765
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
        // because the declaring class is always ValidatedInput, while the called class
        // may not resolve to ValidatedInput when called through template/generic types.
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
                        if ($keyAtomic instanceof TLiteralString) {
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

        // Only narrow input() — it returns the raw value, so the validation rule type applies.
        // str()/string() always return Stringable, collect() always returns Collection,
        // regardless of the validation rule — let those fall through to the stub return type.
        if ($methodName !== 'input') {
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
                /** @psalm-var class-string */
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
     */
    private static function buildArrayShape(array $rules): ?Union
    {
        if ($rules === []) {
            return null;
        }

        $root = new ValidationRuleNode();

        foreach ($rules as $field => $resolvedRule) {
            $segments = \explode('.', $field);
            self::insertIntoTree($root, $segments, $resolvedRule);
        }

        return self::buildUnionFromTree($root);
    }

    /**
     * Insert a resolved rule into a nested tree structure based on dot-notation segments.
     *
     * @param list<string> $segments
     */
    private static function insertIntoTree(ValidationRuleNode $node, array $segments, ResolvedRule $resolvedRule): void
    {
        $key = \array_shift($segments);

        if ($key === null) {
            return;
        }

        $node->children[$key] ??= new ValidationRuleNode();

        if ($segments === []) {
            $node->children[$key]->rule = $resolvedRule;
        } else {
            self::insertIntoTree($node->children[$key], $segments, $resolvedRule);
        }
    }

    /**
     * Recursively convert a nested tree into TKeyedArray Union types.
     *
     * Wildcard patterns (tags.*, items.*.id) are already resolved by
     * ValidationRuleAnalyzer::resolveRules() before reaching this method,
     * so this method only handles plain dot-notation nesting.
     *
     * Known limitation: when all descendants of an intermediate node are optional
     * (sometimes/no required), the parent group could be absent from validated() output,
     * but we don't mark it as possibly_undefined. Fixing this would require checking
     * all descendants recursively — deferred for simplicity.
     *
     * @psalm-mutation-free
     */
    private static function buildUnionFromTree(ValidationRuleNode $node): ?Union
    {
        if ($node->children === []) {
            return null;
        }

        /** @var array<string, Union> $properties */
        $properties = [];

        foreach ($node->children as $key => $child) {
            if ($key === '*') {
                continue;
            }

            // Leaf node — has a rule but no nested children.
            if ($child->children === []) {
                if ($child->rule === null) {
                    continue;
                }

                $fieldType = $child->rule->type;
                if ($child->rule->sometimes || !$child->rule->required) {
                    $fieldType = $fieldType->setPossiblyUndefined(true);
                }

                $properties[$key] = $fieldType;
                continue;
            }

            // Branch node — recurse into nested children.
            $nested = self::buildUnionFromTree($child);

            if ($nested instanceof Union) {
                if ($child->rule !== null && ($child->rule->sometimes || !$child->rule->required)) {
                    $nested = $nested->setPossiblyUndefined(true);
                }

                $properties[$key] = $nested;
            }
        }

        if ($properties === []) {
            return null;
        }

        /** @var non-empty-array<string, Union> $properties */
        return new Union([new TKeyedArray($properties)]);
    }
}
