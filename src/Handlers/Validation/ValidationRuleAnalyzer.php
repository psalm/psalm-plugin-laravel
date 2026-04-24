<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node;
use Psalm\DocComment;
use Psalm\Exception\DocblockParseException;
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
use Psalm\Type\TaintKind;
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
    /**
     * Synthetic segment prefix used by extractRulePairsFromArrayNode to encode
     * a custom Rule object (e.g. `new EmailWithDnsRule()` or `X::make(...)`)
     * as a string segment consumable by resolveRuleSegments. Rule names in
     * Laravel cannot contain a colon followed by a backslash-separated FQN,
     * so there is no collision risk with real rule names.
     */
    private const CLASS_SEGMENT_PREFIX = 'class:';

    /**
     * Authoritative taint-escape table for first-party Laravel rule classes
     * whose object form carries the same safety guarantees as their string-rule
     * equivalent in {@see ruleToRemovedTaints()}. Consulted before any class
     * docblock lookup — Laravel's own Rule classes do not and should not carry
     * `@psalm-taint-escape` annotations.
     *
     * Keys are lowercase FQNs (matching {@see classRuleRemovedTaints()}'s
     * cache-key convention). Any class not listed here falls through to the
     * user-authored docblock path and contributes 0 unless annotated.
     */
    private const FIRST_PARTY_RULE_ESCAPES = [
        // Mirrors the 'email' string rule: Laravel's email validators reject
        // raw whitespace and control characters, so the value is safe for
        // header/cookie sinks. Other taints (HTML, SQL, …) are preserved.
        'illuminate\\validation\\rules\\email'
            => TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE,
        // Mirrors the 'numeric' string rule: the value contains no meta-chars.
        'illuminate\\validation\\rules\\numeric' => TaintKind::ALL_INPUT,
        // Mirrors the 'in:' string rule: whitelist-bounded values.
        'illuminate\\validation\\rules\\in' => TaintKind::ALL_INPUT,
        // Mirrors the 'date' / 'date_format' string rules: the object form
        // always emits at least one of those via Rules\Date::__toString(),
        // and every fluent method on Rules\Date (format, past, future,
        // beforeToday, between, …) returns $this and only adds
        // before/after/before_or_equal/after_or_equal constraints — all of
        // which are themselves ALL_INPUT-escaped in ruleToRemovedTaints().
        'illuminate\\validation\\rules\\date' => TaintKind::ALL_INPUT,
    ];

    /**
     * Map from {@see \Illuminate\Validation\Rule} facade method name to the
     * concrete `Illuminate\Validation\Rules\*` class it returns. Drives the
     * fluent-builder resolution in {@see resolveRuleObjectClassName()}.
     *
     * Only methods whose return class is a single, stable `Rules\*` type are
     * listed. `Rule::when()`, `Rule::unique()`, `Rule::exists()`,
     * `Rule::dimensions()`, etc. are intentionally excluded because their
     * output has no value-shape guarantee that would warrant taint escape.
     *
     * Method-name keys are lowercased (PHP method lookup is case-insensitive).
     * Values are stored as exact-case FQNs; the lowercase form used by
     * {@see FIRST_PARTY_RULE_ESCAPES} is derived in {@see classRuleRemovedTaints()}.
     */
    private const RULE_FACADE_METHOD_RETURN_CLASS = [
        'email' => \Illuminate\Validation\Rules\Email::class,
        'in' => \Illuminate\Validation\Rules\In::class,
        'notin' => \Illuminate\Validation\Rules\NotIn::class,
        'numeric' => \Illuminate\Validation\Rules\Numeric::class,
        'date' => \Illuminate\Validation\Rules\Date::class,
        'enum' => \Illuminate\Validation\Rules\Enum::class,
        'file' => \Illuminate\Validation\Rules\File::class,
        'imagefile' => \Illuminate\Validation\Rules\ImageFile::class,
    ];

    /**
     * Lowercased FQN of the Rule facade, pre-computed to avoid calling
     * {@see \strtolower()} on a class constant for every resolved static call.
     * Kept as a companion to {@see RULE_FACADE_METHOD_RETURN_CLASS}.
     */
    private const RULE_FACADE_LOWER_FQN = 'illuminate\\validation\\rule';

    /** @var array<string, array<string, ResolvedRule>|null> */
    private static array $cache = [];

    /**
     * Per-class cache of the OR-ed `@psalm-taint-escape` bitmask read from a
     * Rule class's own docblock. Keyed by lowercase FQN.
     *
     * @var array<string, int>
     */
    private static array $classTaintCache = [];

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
     * Look up a resolved rule for an accessor key, with a fallback for
     * indexed access to wildcard-array-shaped fields.
     *
     * `resolveRules()` stores the element rule of a `'field.*'` pattern under
     * the parent key `'field'` (so a whole-array read like
     * `$request->input('field')` resolves directly). An indexed read
     * (`$request->input('field.0')`) arrives with the full dotted key; when
     * the exact-key lookup misses, strip a single trailing `.\d+` segment and
     * retry under the parent key. That covers the leaf-wildcard case
     * highlighted in issue #838 — bulk-input endpoints (mass invite, tag
     * arrays, address-book import) where `'field.*'` is the idiomatic rule.
     *
     * The fallback is deliberately scoped to one trailing numeric segment.
     * Nested wildcards (`'addresses.*.email'` accessed as
     * `'addresses.0.email'`) and wildcards with non-numeric segments
     * (`'preferences.*.email'` accessed as `'preferences.us-west.email'`)
     * are out of scope — see issue #838's "Out of scope" section. The
     * nested case is locked in by
     * `SafeInlineValidateNestedWildcardKnownLimitation.phpt` so any future
     * deeper-walk implementation is a deliberate, reviewed change.
     *
     * Narrow-case soundness note: on a scalar rule like
     * `'phone' => 'digits:10'`, a dotted read `$request->input('phone.0')`
     * would also strip to `'phone'` and apply the scalar rule's escape.
     * Laravel's `input()` with a dotted key on a scalar field returns
     * `null` at runtime, so no actual validated data flows through the
     * sink in that construction — there is no practical exploitation
     * vector for the false-negative direction, and the precision cost of
     * tracking wildcard origin in the rule map isn't worth it today.
     *
     * @param array<string, ResolvedRule> $rules
     *
     * @psalm-pure
     */
    public static function lookupRuleByKey(array $rules, string $key): ?ResolvedRule
    {
        $rule = $rules[$key] ?? null;

        if ($rule !== null) {
            return $rule;
        }

        if (\preg_match('/^(.+)\.\d+$/', $key, $matches) === 1) {
            return $rules[$matches[1]] ?? null;
        }

        return null;
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
        $removedTaints = 0;
        $nullable = false;
        $sometimes = false;
        $required = false;

        foreach ($segments as $segment) {
            $segment = \trim($segment);

            if ($segment === '') {
                continue;
            }

            // Synthetic segment for a custom Rule object. Contributes only to
            // the taint escape bitmask — the rule's runtime behavior (type,
            // nullable, required) is opaque to the plugin.
            if (\str_starts_with($segment, self::CLASS_SEGMENT_PREFIX)) {
                $removedTaints |= self::classRuleRemovedTaints(
                    \substr($segment, \strlen(self::CLASS_SEGMENT_PREFIX)),
                );

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

            $removedTaints |= self::ruleToRemovedTaints($ruleName);
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
     * Map a validation rule name to a taint-escape bitmask.
     *
     * Returns the set of taint kinds that this rule makes safe.
     * Adding a new rule = adding one line to this match.
     *
     * @psalm-pure
     */
    private static function ruleToRemovedTaints(string $rule): int
    {
        return match ($rule) {
            // Strictly character-constrained — values cannot contain any meta-characters
            // that matter to known input sinks, so we escape every INPUT_* kind.
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
            'in' => TaintKind::ALL_INPUT,

            // IP literals: restricted to digits / dots / colons / hex letters.
            // Safe everywhere except SSRF — a syntactically valid IP can still
            // resolve to an internal host (169.254.169.254, 127.0.0.1, ::1, etc.).
            'ip', 'ipv4', 'ipv6' => TaintKind::ALL_INPUT & ~TaintKind::INPUT_SSRF,

            // Emails: Laravel's email validators reject raw whitespace and control
            // characters, which is sufficient to prevent CRLF header injection in
            // practice. We only escape header (and cookie, which is header-framed)
            // taint — RFC 5322 quoted local parts can still embed quotes, angle
            // brackets and shell meta, so SQL / HTML / LDAP / XPath / SHELL / SSRF
            // taint is preserved.
            // Caveat: RFCValidation technically permits folding whitespace (CRLF+WSP)
            // inside quoted-strings; if that matters, switch the rule to
            // `email:strict` or `email:filter`. The plugin does not yet distinguish
            // these modes, so we take the pragmatic stance.
            'email' => TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE,

            // URLs: filter_var / RFC validation rejects CRLF and raw whitespace,
            // so it's safe for header/cookie sinks. A validated URL is still the
            // primary SSRF vector, and path / query components may carry HTML,
            // SQL, shell, or XPath payloads — those taints are preserved.
            'url', 'active_url' => TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE,

            // file, image, mimes, mimetypes → keep taint (file names/paths/contents are user-controlled)
            // string, json, regex, required, max, min, etc. → keep all taint
            default => 0,
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

            $shape = TKeyedArray::make($properties);
            $listType = new Union([
                Type::getListAtomic(new Union([$shape])),
            ]);

            if ($parentRule?->nullable) {
                $listType = Type::combineUnionTypes($listType, Type::getNull());
            }

            // Aggregate child taint removal — if ALL children remove all taint,
            // the list container is also safe. Otherwise keep tainted.
            $aggregatedTaint = TaintKind::ALL_INPUT;

            foreach ($children as $childRule) {
                $aggregatedTaint &= $childRule->removedTaints;
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
     * Not `@psalm-mutation-free` because resolving a Rule object's class name
     * reads `resolvedName` attributes via PhpParser's impure `getAttribute()`.
     *
     * @return array<string, list<string>>|null
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

                    if ($ruleItem->value instanceof Node\Scalar\String_) {
                        $segments[] = $ruleItem->value->value;

                        continue;
                    }

                    // Custom Rule object — capture the class FQN so resolveRuleSegments
                    // can OR the class's own @psalm-taint-escape bits into removedTaints.
                    $ruleClassFqn = self::resolveRuleObjectClassName($ruleItem->value);

                    if ($ruleClassFqn !== null) {
                        $segments[] = self::CLASS_SEGMENT_PREFIX . $ruleClassFqn;
                    }
                }

                if ($segments !== []) {
                    $rules[$fieldName] = $segments;
                }

                continue;
            }

            // Value is a variable, function call, or unsupported Rule expression — cannot resolve statically
        }

        return $rules !== [] ? $rules : null;
    }

    /**
     * Resolve the class FQN of a Rule object expression in a rules() array.
     *
     * Recognises, in order:
     *   - `new App\Rules\X()` → `App\Rules\X`
     *   - `App\Rules\X::make(...)` (user-authored static factory) → `App\Rules\X`
     *   - `Illuminate\Validation\Rule::email()` and the other Rule-facade
     *     fluent builders → the concrete `Rules\*` class from
     *     {@see RULE_FACADE_METHOD_RETURN_CLASS}.
     *   - Any chain of the form `<root>->fluent()->fluent()` (including the
     *     nullsafe variant `?->`) where `<root>` is one of the above, by
     *     unwrapping the outer method-call nodes. Laravel's first-party
     *     fluent builders (`Email::preventSpoofing`, `Numeric::between`, …)
     *     return `$this`, so the chain's top-level value is always an
     *     instance of the root's class. User-authored `X::make()->y()`
     *     chains remain best-effort: if `y()` returns a different class
     *     (decorator pattern) the analyzer will read `X`'s docblock, which
     *     is the same soundness caveat as the existing `X::make()` path.
     *
     * For a user-authored `X::make(...)` we read the docblock of `X` itself,
     * which is sound for the common `new self()` / `new static()` factory
     * pattern. The Rule-facade special case bypasses that assumption because
     * `Rule::email()` returns `Rules\Email`, not `Rule` itself.
     *
     * Unmapped Rule-facade methods (`Rule::unique()`, `Rule::exists()`, …)
     * fall back to the `Rule` class itself: the docblock path then finds no
     * `@psalm-taint-escape` and contributes 0 bits. This preserves the
     * presence of the field in the rules map so downstream type inference
     * still narrows `validated()` output for fields guarded only by these
     * builders.
     *
     * Dynamic (`new $class()`) or other unhandled expressions return null,
     * matching the parser limits elsewhere in {@see extractRulePairsFromArrayNode()}.
     */
    private static function resolveRuleObjectClassName(Node\Expr $expr): ?string
    {
        // Unwrap outer fluent calls (standard and nullsafe): chained calls
        // like `Rule::email()->preventSpoofing()` or `Rule::email()?->strict()`
        // resolve to the class of the innermost receiver.
        while ($expr instanceof Node\Expr\MethodCall
            || $expr instanceof Node\Expr\NullsafeMethodCall
        ) {
            $expr = $expr->var;
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            /** @var string|null $resolved */
            $resolved = $expr->class->getAttribute('resolvedName');

            return \is_string($resolved) ? $resolved : null;
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name) {
            /** @var string|null $resolved */
            $resolved = $expr->class->getAttribute('resolvedName');

            if (!\is_string($resolved)) {
                return null;
            }

            // Laravel's Rule facade: translate `Rule::email()` to the concrete
            // `Rules\Email` class, so the escape table can match. Unmapped
            // methods on Rule (unique, exists, dimensions, when, …) fall back
            // to Rule itself so the field still surfaces as a rule segment;
            // the docblock path then contributes 0 bits since Rule carries no
            // `@psalm-taint-escape` annotation.
            if (\strtolower($resolved) === self::RULE_FACADE_LOWER_FQN) {
                if (!$expr->name instanceof Node\Identifier) {
                    return $resolved;
                }

                return self::RULE_FACADE_METHOD_RETURN_CLASS[\strtolower($expr->name->name)]
                    ?? $resolved;
            }

            return $resolved;
        }

        return null;
    }

    /**
     * Read `@psalm-taint-escape <kind>` annotations from a Rule class's own
     * docblock and return the OR-ed bitmask of TaintKind bits they cover.
     *
     * Psalm stores `removed_taints` on FunctionLikeStorage only — class-level
     * escapes are not part of ClassLikeStorage — so we parse the class's raw
     * docblock here. Only the bare form (`@psalm-taint-escape header`) is
     * honoured; the conditional form (`@psalm-taint-escape (...)`) is ignored
     * because it is defined per-parameter and has no meaning at class scope.
     *
     * Unknown kinds map to 0 and are silently dropped. Unlike Psalm's own
     * `Codebase::getOrRegisterTaint()` (used in FunctionLikeDocblockScanner),
     * which registers unfamiliar names as custom taint kinds, this lookup
     * honours only the built-in `TaintKind::TAINT_NAMES` set. A mistyped kind
     * (e.g. `heder` instead of `header`) contributes no escape and therefore
     * leaves taint intact, which produces extra reports (the false-positive
     * direction) — double-check spellings against the kind table in
     * `docs/contributing/taint-analysis.md`.
     *
     * The FQN is treated as an opaque string — if the class does not exist in
     * the codebase, the storage lookup below fails and we return 0.
     */
    private static function classRuleRemovedTaints(string $classFqn): int
    {
        $cacheKey = \strtolower($classFqn);

        if (\array_key_exists($cacheKey, self::$classTaintCache)) {
            return self::$classTaintCache[$cacheKey];
        }

        // First-party Laravel rule classes: short-circuit the docblock lookup.
        // Laravel's own `Illuminate\Validation\Rules\*` classes carry no
        // `@psalm-taint-escape` annotation, so without this authoritative
        // table the object form of a built-in rule (`new Rules\Email()`,
        // `Rule::numeric()`, …) would silently lose the taint-escape that
        // its string equivalent ('email', 'numeric') already provides.
        if (isset(self::FIRST_PARTY_RULE_ESCAPES[$cacheKey])) {
            return self::$classTaintCache[$cacheKey]
                = self::FIRST_PARTY_RULE_ESCAPES[$cacheKey];
        }

        try {
            $codebase = ProjectAnalyzer::getInstance()->getCodebase();
        } catch (\RuntimeException|\Error) {
            return self::$classTaintCache[$cacheKey] = 0;
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($cacheKey);
        } catch (\InvalidArgumentException) {
            return self::$classTaintCache[$cacheKey] = 0;
        }

        $filePath = $storage->location?->file_path;

        if ($filePath === null) {
            return self::$classTaintCache[$cacheKey] = 0;
        }

        try {
            $statements = $codebase->getStatementsForFile($filePath);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return self::$classTaintCache[$cacheKey] = 0;
        }

        $classNode = self::findClassNode($statements, $storage->name);

        if (!$classNode instanceof \PhpParser\Node\Stmt\Class_) {
            return self::$classTaintCache[$cacheKey] = 0;
        }

        $docComment = $classNode->getDocComment();

        if (!$docComment instanceof \PhpParser\Comment\Doc) {
            return self::$classTaintCache[$cacheKey] = 0;
        }

        try {
            $parsed = DocComment::parsePreservingLength($docComment, true);
        } catch (DocblockParseException) {
            return self::$classTaintCache[$cacheKey] = 0;
        }

        $escapeTags = $parsed->tags['psalm-taint-escape'] ?? [];

        $bits = 0;

        foreach ($escapeTags as $tagLine) {
            $kind = \trim($tagLine);

            if ($kind === '' || $kind[0] === '(') {
                // Conditional form is parameter-scoped; has no meaning on a class.
                continue;
            }

            // Psalm's parser keeps only the first whitespace-separated token.
            $kind = \explode(' ', $kind)[0];

            $bits |= TaintKind::TAINT_NAMES[$kind] ?? 0;
        }

        return self::$classTaintCache[$cacheKey] = $bits;
    }

    /**
     * Locate the class AST node for a known FQN inside the given file's statements.
     *
     * @param list<Node\Stmt> $statements
     * @psalm-mutation-free
     */
    private static function findClassNode(array $statements, string $className): ?Node\Stmt\Class_
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $namespaceName = $stmt->name?->toString() ?? '';

                foreach ($stmt->stmts as $nsStmt) {
                    if ($nsStmt instanceof Node\Stmt\Class_
                        && self::classNameMatches($nsStmt, $className, $namespaceName)
                    ) {
                        return $nsStmt;
                    }
                }

                continue;
            }

            if ($stmt instanceof Node\Stmt\Class_
                && self::classNameMatches($stmt, $className, '')
            ) {
                return $stmt;
            }
        }

        return null;
    }
}
