<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNumericString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\TaintKindGroup;
use Psalm\Type\Union;

/**
 * Reads validation rules from FormRequest classes or inline validate() calls,
 * parses rule strings, and resolves them to Psalm types and taint escape bitmasks.
 *
 * Mirrors the architecture of {@see \Psalm\LaravelPlugin\Handlers\Console\CommandDefinitionAnalyzer}:
 * reads AST from source files, walks class hierarchy, caches results.
 */
final class ValidationRuleAnalyzer
{
    /** @var array<string, array<string, ResolvedRule>|null> */
    private static array $cache = [];

    /**
     * Get resolved rules for a FormRequest subclass by reading its rules() method from AST.
     *
     * @param class-string $formRequestClass
     * @return array<string, ResolvedRule>|null  field => ResolvedRule, null if unresolvable
     */
    public static function getRulesForFormRequest(string $formRequestClass): ?array
    {
        $cacheKey = \strtolower($formRequestClass);

        if (\array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $rawRules = self::extractRulesFromClass($formRequestClass);

        if ($rawRules === null) {
            return self::$cache[$cacheKey] = null;
        }

        return self::$cache[$cacheKey] = self::resolveRules($rawRules);
    }

    /**
     * Parse rules from an inline validate() call's first argument.
     *
     * Expects the first argument to be an array literal with string keys.
     *
     * @param list<Node\Arg> $args
     * @return array<string, ResolvedRule>|null
     */
    public static function getRulesFromValidateArgs(array $args): ?array
    {
        if ($args === []) {
            return null;
        }

        $rulesExpr = $args[0]->value;

        if (!$rulesExpr instanceof Node\Expr\Array_) {
            return null;
        }

        $rawRules = self::extractRulePairsFromArrayNode($rulesExpr);

        if ($rawRules === null) {
            return null;
        }

        return self::resolveRules($rawRules);
    }

    /**
     * Resolves a list of rule segments (already split from pipe-delimited or array format)
     * into a ResolvedRule with inferred type, taint escape bitmask, and modifier flags.
     *
     * @param list<string> $segments
     */
    public static function resolveRuleSegments(array $segments): ResolvedRule
    {
        $type = null;
        /** @var list<string> */
        $removedTaints = [];
        $nullable = false;
        $sometimes = false;
        $required = false;

        foreach ($segments as $segment) {
            $segment = \trim($segment);

            if ($segment === '') {
                continue;
            }

            [$ruleName, $ruleParam] = self::splitRule($segment);

            // Modifiers
            if ($ruleName === 'nullable') {
                $nullable = true;

                continue;
            }

            if ($ruleName === 'sometimes') {
                $sometimes = true;

                continue;
            }

            // Unconditional presence rules — field is guaranteed to exist in validated() output.
            // Conditional variants (required_if, present_with, accepted_if, etc.) depend on
            // runtime conditions we cannot evaluate statically — they don't guarantee presence.
            // Keep in sync with Laravel's validation rules in Illuminate\Validation\Concerns\ValidatesAttributes.
            if (\in_array($ruleName, ['required', 'present', 'accepted', 'declined'], true)
            ) {
                $required = true;
            }

            // Type-bearing rules (first one wins for type, all accumulate taint)
            $ruleType = self::ruleToType($ruleName, $ruleParam);

            if ($ruleType instanceof Union && !$type instanceof Union) {
                $type = $ruleType;
            }

            // Union of removed taints: accumulate across all rule segments
            $removedTaints = \array_values(\array_unique(\array_merge($removedTaints, self::ruleToRemovedTaints($ruleName))));
        }

        // Default: if no type rule matched, return mixed (don't narrow)
        $type ??= Type::getMixed();

        // nullable modifier: add null to type union
        if ($nullable) {
            $type = Type::combineUnionTypes($type, Type::getNull());
        }

        return new ResolvedRule($type, $removedTaints, $nullable, $sometimes, $required);
    }

    /**
     * Map a validation rule name to a Psalm type.
     *
     * Adding a new rule = adding one line to this match.
     */
    private static function ruleToType(string $rule, ?string $param): ?Union
    {
        return match ($rule) {
            'string' => Type::getString(),
            'integer' => new Union([new TInt(), new TNumericString()]),
            'numeric' => new Union([new TInt(), new TFloat(), new TNumericString()]),
            'decimal',
            'digits', 'digits_between' => new Union([new TNumericString()]),
            // Laravel's boolean rule accepts: true, false, 0, 1, '0', '1'
            'boolean' => self::booleanRuleType(),
            // Laravel's accepted rule accepts: 'yes', 'on', 1, '1', true
            'accepted', 'accepted_if' => self::acceptedRuleType(),
            // Laravel's declined rule accepts: 'no', 'off', 0, '0', false
            'declined', 'declined_if' => self::declinedRuleType(),
            'array' => new Union([
                new TArray([Type::getArrayKey(), Type::getMixed()]),
            ]),
            'list' => new Union([
                Type::getListAtomic(Type::getMixed()),
            ]),
            'file', 'image',
            'mimes', 'mimetypes' => new Union([
                new TNamedObject(\Illuminate\Http\UploadedFile::class),
            ]),
            'in' => self::inRuleToLiteralUnion($param),
            'uuid', 'ulid',
            'alpha', 'alpha_num', 'alpha_dash',
            'hex_color', 'mac_address',
            'date', 'date_format',
            'before', 'before_or_equal',
            'after', 'after_or_equal',
            'date_equals',
            'timezone',
            'email', 'url', 'active_url',
            'ip', 'ipv4', 'ipv6',
            'json' => Type::getString(),
            default => null,
        };
    }

    /**
     * All input-related taint kinds — delegates to Psalm's own canonical list.
     *
     * Equivalent to Psalm 7's TaintKind::ALL_INPUT bitmask.
     * Using TaintKindGroup::ALL_INPUT (Psalm 6 public API) avoids duplicating the list
     * and automatically picks up any new INPUT_* kinds added in future Psalm 6.x releases.
     *
     * @return list<string>
     * @psalm-pure
     */
    public static function allInputTaints(): array
    {
        return TaintKindGroup::ALL_INPUT;
    }

    /**
     * Map a validation rule name to a list of escaped taint kinds.
     *
     * Returns the set of taint kinds that this rule makes safe.
     * Adding a new rule = adding one line to this match.
     *
     * @return list<string>
     * @psalm-pure
     */
    private static function ruleToRemovedTaints(string $rule): array
    {
        return match ($rule) {
            'integer', 'numeric', 'boolean',
            'decimal', 'digits', 'digits_between',
            'accepted', 'accepted_if',
            'declined', 'declined_if',
            'uuid', 'ulid',
            'alpha', 'alpha_num', 'alpha_dash',
            'hex_color', 'mac_address',
            'date', 'date_format',
            'before', 'before_or_equal',
            'after', 'after_or_equal',
            'date_equals',
            'timezone',
            'in' => self::allInputTaints(),
            // file, image, mimes, mimetypes → keep taint (file names/paths/contents are user-controlled)
            // string, email, url, ip, json, regex, required, max, min, etc. → keep all taint
            default => [],
        };
    }

    /**
     * Convert an 'in:a,b,c' parameter to a literal string union type.
     */
    private static function inRuleToLiteralUnion(?string $param): Union
    {
        if ($param === null || $param === '') {
            return Type::getString();
        }

        try {
            $values = \explode(',', $param);
            $atomics = \array_map(
                static fn(string $v): TLiteralString => TLiteralString::make(\trim($v)),
                $values,
            );

            return new Union($atomics);
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            // TLiteralString::make() requires Psalm Config to be initialized.
            // When called outside of Psalm analysis context (e.g. unit tests),
            // fall back to a plain string type.
            return Type::getString();
        }
    }

    /**
     * Build the type for Laravel's boolean validation rule.
     *
     * Accepts: true, false, 0, 1, '0', '1'. TLiteralString requires Psalm Config,
     * so we fall back to bool|int when running outside analysis context (unit tests).
     *
     * @psalm-external-mutation-free
     */
    private static function booleanRuleType(): Union
    {
        try {
            return Type::combineUnionTypes(
                Type::getBool(),
                new Union([
                    new TLiteralInt(0),
                    new TLiteralInt(1),
                    TLiteralString::make('0'),
                    TLiteralString::make('1'),
                ]),
            );
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return Type::combineUnionTypes(
                Type::getBool(),
                new Union([new TLiteralInt(0), new TLiteralInt(1)]),
            );
        }
    }

    /**
     * Build the type for Laravel's accepted validation rule.
     *
     * Accepts: 'yes', 'on', 1, '1', true, 'true'.
     *
     * @psalm-external-mutation-free
     */
    private static function acceptedRuleType(): Union
    {
        try {
            return new Union([
                new TTrue(),
                new TLiteralInt(1),
                TLiteralString::make('yes'),
                TLiteralString::make('on'),
                TLiteralString::make('1'),
                TLiteralString::make('true'),
            ]);
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return Type::combineUnionTypes(
                Type::getTrue(),
                new Union([new TLiteralInt(1)]),
            );
        }
    }

    /**
     * Build the type for Laravel's declined validation rule.
     *
     * Accepts: 'no', 'off', 0, '0', false, 'false'.
     *
     * @psalm-external-mutation-free
     */
    private static function declinedRuleType(): Union
    {
        try {
            return new Union([
                new TFalse(),
                new TLiteralInt(0),
                TLiteralString::make('no'),
                TLiteralString::make('off'),
                TLiteralString::make('0'),
                TLiteralString::make('false'),
            ]);
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return Type::combineUnionTypes(
                Type::getFalse(),
                new Union([new TLiteralInt(0)]),
            );
        }
    }

    /**
     * Split a rule segment into name and optional parameter.
     *
     * 'in:a,b,c' → ['in', 'a,b,c']
     * 'required' → ['required', null]
     * 'max:255'  → ['max', '255']
     *
     * @return array{string, string|null}
     * @psalm-pure
     */
    private static function splitRule(string $segment): array
    {
        $colonPos = \strpos($segment, ':');

        if ($colonPos === false) {
            return [\strtolower($segment), null];
        }

        return [
            \strtolower(\substr($segment, 0, $colonPos)),
            \substr($segment, $colonPos + 1),
        ];
    }

    /**
     * Convert a flat rule map (which may contain wildcards) into resolved rules.
     *
     * Handles single-level wildcards:
     *   'items.*' → wraps in list<T>
     *   'items.*.name' → builds list<array{name: T}>
     *
     * @param array<string, list<string>> $rawRules field => rule segments
     * @return array<string, ResolvedRule>
     */
    private static function resolveRules(array $rawRules): array
    {
        // Separate top-level fields from wildcard paths
        $topLevel = [];
        // Keyed by parent field name → list of [childField, resolvedRule]
        /** @var array<string, array<string, ResolvedRule>> $wildcardChildren */
        $wildcardChildren = [];
        /** @var array<string, ResolvedRule> $wildcardDirect */
        $wildcardDirect = [];

        foreach ($rawRules as $field => $segments) {
            // 'items.*.name' pattern
            if (\str_contains($field, '.*.')) {
                $dotStarPos = \strpos($field, '.*.');
                \assert($dotStarPos !== false);
                $parent = \substr($field, 0, $dotStarPos);
                $child = \substr($field, $dotStarPos + 3);

                // Skip deeper nesting for now (items.*.tags.*.name)
                if (\str_contains($child, '.*')) {
                    continue;
                }

                $wildcardChildren[$parent][$child] = self::resolveRuleSegments($segments);

                continue;
            }

            // 'items.*' pattern (direct wildcard element)
            if (\str_ends_with($field, '.*')) {
                $parent = \substr($field, 0, -2);
                $wildcardDirect[$parent] = self::resolveRuleSegments($segments);

                continue;
            }

            // Regular top-level field
            $topLevel[$field] = self::resolveRuleSegments($segments);
        }

        // Build list types from wildcards
        foreach ($wildcardDirect as $parent => $elementRule) {
            $parentRule = $topLevel[$parent] ?? null;

            $listType = new Union([
                Type::getListAtomic($elementRule->type),
            ]);

            if ($parentRule?->nullable) {
                $listType = Type::combineUnionTypes($listType, Type::getNull());
            }

            $topLevel[$parent] = new ResolvedRule(
                $listType,
                $elementRule->removedTaints,
                $parentRule?->nullable ?? false,
                $parentRule?->sometimes ?? false,
                $parentRule?->required ?? false,
            );
        }

        // Build list<array{...}> types from wildcard children
        foreach ($wildcardChildren as $parent => $children) {
            if ($children === []) {
                continue;
            }

            $parentRule = $topLevel[$parent] ?? null;

            /** @var non-empty-array<string, Union> $properties */
            $properties = [];

            foreach ($children as $childField => $childRule) {
                $childType = $childRule->type;

                if ($childRule->sometimes || !$childRule->required) {
                    $childType = $childType->setPossiblyUndefined(true);
                }

                $properties[$childField] = $childType;
            }

            $shape = new TKeyedArray($properties);
            $listType = new Union([
                Type::getListAtomic(new Union([$shape])),
            ]);

            if ($parentRule?->nullable) {
                $listType = Type::combineUnionTypes($listType, Type::getNull());
            }

            // Aggregate child taint removal — if ALL children remove all taint,
            // the list container is also safe. Otherwise keep tainted.
            // Intersection: only keep taint kinds removed by every child rule.
            $aggregatedTaint = self::allInputTaints();

            foreach ($children as $childRule) {
                $aggregatedTaint = \array_values(\array_intersect($aggregatedTaint, $childRule->removedTaints));
            }

            $topLevel[$parent] = new ResolvedRule(
                $listType,
                $aggregatedTaint,
                $parentRule?->nullable ?? false,
                $parentRule?->sometimes ?? false,
                $parentRule?->required ?? false,
            );
        }

        return $topLevel;
    }

    /**
     * Extract the rules() method's return array from a FormRequest class AST.
     * Walks the class hierarchy (child → parent) to find the first declaration.
     *
     * @param class-string $formRequestClass
     * @return array<string, list<string>>|null  field => rule segments
     */
    private static function extractRulesFromClass(string $formRequestClass): ?array
    {
        try {
            $codebase = ProjectAnalyzer::getInstance()->getCodebase();
        } catch (\RuntimeException|\Error) {
            return null;
        }

        $currentClass = \strtolower($formRequestClass);

        while ($currentClass !== '') {
            try {
                $storage = $codebase->classlike_storage_provider->get($currentClass);
            } catch (\InvalidArgumentException) {
                break;
            }

            $filePath = $storage->location?->file_path;

            if (isset($storage->methods['rules']) && $filePath !== null) {
                try {
                    $statements = $codebase->getStatementsForFile($filePath);
                    $arrayNode = self::findRulesMethodReturn($statements, $storage->name);

                    if ($arrayNode instanceof \PhpParser\Node\Expr\Array_) {
                        return self::extractRulePairsFromArrayNode($arrayNode);
                    }
                } catch (\InvalidArgumentException|\UnexpectedValueException) {
                    // File unreadable — fall through to parent
                }
            }

            $currentClass = \strtolower($storage->parent_class ?? '');
        }

        return null;
    }

    /**
     * Walk AST statements to find the rules() method's return array in the given class.
     *
     * @param list<Node\Stmt> $statements
     * @psalm-mutation-free
     */
    private static function findRulesMethodReturn(array $statements, string $className): ?Node\Expr\Array_
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $result = self::findRulesInNamespace($stmt, $className);

                if ($result instanceof \PhpParser\Node\Expr\Array_) {
                    return $result;
                }

                continue;
            }

            if (!$stmt instanceof Node\Stmt\Class_) {
                continue;
            }

            if (!self::classNameMatches($stmt, $className, '')) {
                continue;
            }

            return self::findRulesReturnInClass($stmt);
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function findRulesInNamespace(Node\Stmt\Namespace_ $namespace, string $className): ?Node\Expr\Array_
    {
        $namespaceName = $namespace->name?->toString() ?? '';

        foreach ($namespace->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Class_) {
                continue;
            }

            if (!self::classNameMatches($stmt, $className, $namespaceName)) {
                continue;
            }

            return self::findRulesReturnInClass($stmt);
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function classNameMatches(Node\Stmt\Class_ $class, string $expectedFqcn, string $namespace): bool
    {
        $shortName = $class->name?->toString() ?? '';
        $fqcn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;

        return \strtolower($fqcn) === \strtolower($expectedFqcn);
    }

    /**
     * Find the rules() method in a class and extract the returned array expression.
     * Only handles simple cases: a method with a single return statement returning an array literal.
     *
     * @psalm-mutation-free
     */
    private static function findRulesReturnInClass(Node\Stmt\Class_ $class): ?Node\Expr\Array_
    {
        foreach ($class->stmts as $classStmt) {
            if (!$classStmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            if (\strtolower($classStmt->name->name) !== 'rules') {
                continue;
            }

            // Walk method body looking for a return statement with an array
            if ($classStmt->stmts === null) {
                return null;
            }

            foreach ($classStmt->stmts as $methodStmt) {
                if ($methodStmt instanceof Node\Stmt\Return_
                    && $methodStmt->expr instanceof Node\Expr\Array_
                ) {
                    return $methodStmt->expr;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Convert a PhpParser array expression to field → rule segments pairs.
     *
     * Handles both formats:
     *   'field' => 'required|string'       → ['field' => ['required', 'string']]
     *   'field' => ['required', 'string']  → ['field' => ['required', 'string']]
     *
     * @return array<string, list<string>>|null
     * @psalm-mutation-free
     */
    private static function extractRulePairsFromArrayNode(Node\Expr\Array_ $array): ?array
    {
        $rules = [];

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            // Key must be a string literal (field name)
            if (!$item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $fieldName = $item->key->value;

            // Value: string literal → pipe-delimited rules
            if ($item->value instanceof Node\Scalar\String_) {
                $rules[$fieldName] = \explode('|', $item->value->value);

                continue;
            }

            // Value: array of strings → rule segments
            if ($item->value instanceof Node\Expr\Array_) {
                $segments = [];

                foreach ($item->value->items as $ruleItem) {
                    if ($ruleItem === null) {
                        continue;
                    }

                    // Only handle string literal rule segments (skip Rule objects)
                    if ($ruleItem->value instanceof Node\Scalar\String_) {
                        $segments[] = $ruleItem->value->value;
                    }
                }

                if ($segments !== []) {
                    $rules[$fieldName] = $segments;
                }

                continue;
            }

            // Value is a variable, function call, or Rule object — cannot resolve statically
        }

        return $rules !== [] ? $rules : null;
    }
}
