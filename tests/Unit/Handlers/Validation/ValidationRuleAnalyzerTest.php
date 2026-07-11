<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Validation\ResolvedRule;
use Psalm\LaravelPlugin\Handlers\Validation\ValidationRuleAnalyzer;
use Psalm\Type\TaintKind;

/**
 * Tests for the rule parsing and type/taint resolution logic in ValidationRuleAnalyzer.
 *
 * Note: AST extraction (reading rules() from FormRequest classes) requires a running
 * Psalm analysis context. These tests verify the parsing/mapping layer via resolveRuleSegments().
 */
#[CoversClass(ValidationRuleAnalyzer::class)]
#[CoversClass(ResolvedRule::class)]
final class ValidationRuleAnalyzerTest extends TestCase
{
    private function resolve(string $ruleString): ResolvedRule
    {
        return ValidationRuleAnalyzer::resolveRuleSegments(\explode('|', $ruleString));
    }

    // --- Type resolution ---

    #[Test]
    public function string_rule_returns_string_type(): void
    {
        $rule = $this->resolve('required|string');

        $this->assertSame('string', $rule->type->getId());
        $this->assertFalse($rule->nullable);
        $this->assertFalse($rule->sometimes);
    }

    #[Test]
    public function integer_rule_returns_int_or_numeric_string(): void
    {
        $rule = $this->resolve('required|integer');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function numeric_rule_returns_float_int_or_numeric_string(): void
    {
        $rule = $this->resolve('numeric');

        $this->assertSame('float|int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function boolean_rule_returns_bool_type(): void
    {
        $rule = $this->resolve('boolean');

        $this->assertTrue($rule->type->hasType('bool'));
    }

    #[Test]
    public function array_rule_returns_array_type(): void
    {
        $rule = $this->resolve('array');

        $this->assertTrue($rule->type->hasType('array'));
    }

    #[Test]
    public function uuid_rule_returns_string(): void
    {
        $rule = $this->resolve('uuid');

        $this->assertSame('string', $rule->type->getId());
    }

    #[Test]
    public function file_rule_returns_uploaded_file(): void
    {
        $rule = $this->resolve('file');

        $this->assertTrue($rule->type->hasType('Illuminate\\Http\\UploadedFile'));
    }

    #[Test]
    public function in_rule_returns_string_type(): void
    {
        // Without Psalm Config initialized, TLiteralString falls back to plain string.
        // In real analysis context, this would return 'admin'|'user'|'guest' literal union.
        $rule = $this->resolve('in:admin,user,guest');

        $this->assertTrue($rule->type->hasType('string'));
    }

    #[Test]
    public function unknown_rule_returns_mixed(): void
    {
        $rule = $this->resolve('required|max:255');

        $this->assertTrue($rule->type->isMixed());
    }

    // --- Modifier tests ---

    #[Test]
    public function nullable_modifier_adds_null(): void
    {
        $rule = $this->resolve('nullable|integer');

        $this->assertTrue($rule->type->hasType('null'));
        $this->assertTrue($rule->type->hasType('int'));
        $this->assertTrue($rule->nullable);
    }

    #[Test]
    public function sometimes_modifier_sets_flag(): void
    {
        $rule = $this->resolve('sometimes|string');

        $this->assertTrue($rule->sometimes);
        $this->assertSame('string', $rule->type->getId());
    }

    #[Test]
    public function required_rule_sets_required_flag(): void
    {
        $rule = $this->resolve('required|string');

        $this->assertTrue($rule->required);
    }

    #[Test]
    public function present_rule_sets_required_flag(): void
    {
        $rule = $this->resolve('present|string');

        $this->assertTrue($rule->required);
    }

    #[Test]
    public function conditional_required_does_not_set_required(): void
    {
        $rule = $this->resolve('required_if:role,admin|string');

        $this->assertFalse($rule->required);
    }

    #[Test]
    public function field_without_presence_rule_is_not_required(): void
    {
        $rule = $this->resolve('string|max:255');

        $this->assertFalse($rule->required);
    }

    // --- Taint resolution ---

    #[Test]
    public function integer_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('integer');

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function string_rule_keeps_all_taint(): void
    {
        $rule = $this->resolve('string');

        $this->assertSame(0, $rule->removedTaints);
    }

    #[Test]
    public function uuid_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('uuid');

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function url_rule_removes_only_header_and_cookie_taint(): void
    {
        $rule = $this->resolve('url');

        $this->assertSame(TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE, $rule->removedTaints);
    }

    #[Test]
    public function ip_rule_removes_all_input_taint_except_ssrf(): void
    {
        $rule = $this->resolve('ip');

        $this->assertSame(TaintKind::ALL_INPUT & ~TaintKind::INPUT_SSRF, $rule->removedTaints);
    }

    #[Test]
    public function email_rule_removes_only_header_and_cookie_taint(): void
    {
        $rule = $this->resolve('email');

        $this->assertSame(TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE, $rule->removedTaints);
    }

    #[Test]
    public function in_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('in:a,b,c');

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function date_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('date');

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function alpha_num_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('alpha_num');

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function decimal_rule_returns_numeric_string(): void
    {
        $rule = $this->resolve('decimal:2');

        $this->assertSame('numeric-string', $rule->type->getId());
        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function digits_rule_returns_numeric_string(): void
    {
        $rule = $this->resolve('digits:4');

        $this->assertSame('numeric-string', $rule->type->getId());
        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function accepted_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('accepted');

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function declined_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('declined');

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function before_rule_returns_string_and_removes_taint(): void
    {
        $rule = $this->resolve('before:2025-01-01');

        $this->assertSame('string', $rule->type->getId());
        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function date_equals_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('date_equals:today');

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function list_rule_returns_list_type(): void
    {
        $rule = $this->resolve('list');

        $this->assertSame('list<mixed>', $rule->type->getId());
    }

    #[Test]
    public function image_rule_returns_uploaded_file(): void
    {
        $rule = $this->resolve('image');

        $this->assertTrue($rule->type->hasType('Illuminate\\Http\\UploadedFile'));
    }

    #[Test]
    public function file_rule_keeps_all_taint(): void
    {
        $rule = $this->resolve('file');

        $this->assertSame(0, $rule->removedTaints);
    }

    #[Test]
    public function image_rule_keeps_all_taint(): void
    {
        $rule = $this->resolve('image');

        $this->assertSame(0, $rule->removedTaints);
    }

    #[Test]
    public function accepted_rule_sets_required_flag(): void
    {
        $rule = $this->resolve('accepted');

        $this->assertTrue($rule->required);
    }

    #[Test]
    public function declined_rule_sets_required_flag(): void
    {
        $rule = $this->resolve('declined');

        $this->assertTrue($rule->required);
    }

    #[Test]
    public function accepted_if_does_not_set_required(): void
    {
        $rule = $this->resolve('accepted_if:role,admin');

        $this->assertFalse($rule->required);
    }

    #[Test]
    public function mimes_rule_returns_uploaded_file(): void
    {
        $rule = $this->resolve('mimes:jpg,png');

        $this->assertTrue($rule->type->hasType('Illuminate\\Http\\UploadedFile'));
    }

    #[Test]
    public function mimetypes_rule_returns_uploaded_file(): void
    {
        $rule = $this->resolve('mimetypes:image/jpeg');

        $this->assertTrue($rule->type->hasType('Illuminate\\Http\\UploadedFile'));
    }

    #[Test]
    public function json_rule_keeps_all_taint(): void
    {
        $rule = $this->resolve('json');

        $this->assertSame(0, $rule->removedTaints);
    }

    #[Test]
    public function boolean_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('boolean');

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    // --- Array rule format ---

    #[Test]
    public function array_format_rules_resolve_correctly(): void
    {
        // Simulates ['required', 'integer', 'max:100'] — max:100 narrows (#1234).
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['required', 'integer', 'max:100']);

        $this->assertSame('int<min, 100>|numeric-string', $rule->type->getId());
        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    // --- Edge cases ---

    #[Test]
    public function sometimes_required_field_is_sometimes_and_required(): void
    {
        // sometimes|required|string — field may be absent, but when present must exist
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['sometimes', 'required', 'string']);

        $this->assertTrue($rule->sometimes);
        $this->assertTrue($rule->required);
        $this->assertSame('string', $rule->type->getId());
    }

    #[Test]
    public function rule_order_does_not_affect_taint(): void
    {
        // Taint removal accumulates regardless of order
        $ruleA = ValidationRuleAnalyzer::resolveRuleSegments(['string', 'integer']);
        $ruleB = ValidationRuleAnalyzer::resolveRuleSegments(['integer', 'string']);

        $this->assertSame($ruleA->removedTaints, $ruleB->removedTaints);
        $this->assertSame(TaintKind::ALL_INPUT, $ruleA->removedTaints);
    }

    #[Test]
    public function first_type_bearing_rule_wins(): void
    {
        // First type-bearing rule determines the type (string before integer)
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['string', 'integer']);

        $this->assertSame('string', $rule->type->getId());
    }

    // --- Custom Rule class segments (#822) ---

    #[Test]
    public function class_segment_for_unknown_class_removes_no_taint(): void
    {
        // Without a Psalm analysis context, the class storage lookup in
        // classRuleRemovedTaints fails and the segment contributes 0. The
        // segment must still be tolerated (no crash) and must not affect
        // the type or presence flags derived from the other segments.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'string', 'class:App\\Rules\\NonExistent'],
        );

        $this->assertSame('string', $rule->type->getId());
        $this->assertSame(0, $rule->removedTaints);
        $this->assertTrue($rule->required);
    }

    #[Test]
    public function class_segment_is_not_a_type_bearing_rule(): void
    {
        // A `class:` segment alone never narrows the type — the handler
        // cannot introspect the Rule's runtime output, only its declared
        // taint escape set.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['class:App\\Rules\\NonExistent'],
        );

        $this->assertTrue($rule->type->isMixed());
        $this->assertFalse($rule->required);
    }

    // --- First-party Illuminate\Validation\Rules\* segments (#828) ---

    #[Test]
    public function class_segment_for_rules_email_removes_header_and_cookie_taint(): void
    {
        // The authoritative FIRST_PARTY_RULE_ESCAPES table short-circuits the
        // docblock lookup, so this resolves even without a Psalm analysis
        // context. The bits mirror the 'email' string rule.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'string', 'class:Illuminate\\Validation\\Rules\\Email'],
        );

        $this->assertSame(
            TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE,
            $rule->removedTaints,
        );
        $this->assertSame('string', $rule->type->getId());
    }

    #[Test]
    public function class_segment_for_rules_numeric_removes_all_input_taint(): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'class:Illuminate\\Validation\\Rules\\Numeric'],
        );

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
        $this->assertTrue($rule->required);
    }

    #[Test]
    public function class_segment_for_rules_in_removes_all_input_taint(): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'class:Illuminate\\Validation\\Rules\\In'],
        );

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function class_segment_for_rules_date_removes_all_input_taint(): void
    {
        // The 'date' string rule escapes ALL_INPUT; the object form must be
        // in parity since Rules\Date::__toString() always emits 'date' or
        // 'date_format:...' as the first constraint.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'class:Illuminate\\Validation\\Rules\\Date'],
        );

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function class_segment_for_rules_enum_removes_all_input_taint(): void
    {
        // Issue #908 follow-on: the `Rules\Enum` class segment is the fallback
        // path used when the analyzer can't statically resolve the enum-class
        // argument (e.g. `new Enum($variable)`). The synthetic `enum:FQN`
        // segment already escapes via ruleToRemovedTaints, so the class:
        // fallback must match for parity — see FIRST_PARTY_RULE_ESCAPES entry.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'class:Illuminate\\Validation\\Rules\\Enum'],
        );

        $this->assertSame(TaintKind::ALL_INPUT, $rule->removedTaints);
    }

    #[Test]
    public function class_segment_for_rules_notin_removes_no_taint(): void
    {
        // NotIn is deliberately not in FIRST_PARTY_RULE_ESCAPES: rejecting a
        // blocklist of values does not constrain the accepted set to a safe
        // shape. In unit-test context ProjectAnalyzer::getInstance() throws,
        // so the function returns 0 without touching the docblock path.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'class:Illuminate\\Validation\\Rules\\NotIn'],
        );

        $this->assertSame(0, $rule->removedTaints);
    }

    /**
     * Defensive guard: these classes are mapped in RULE_FACADE_METHOD_RETURN_CLASS
     * but deliberately omitted from FIRST_PARTY_RULE_ESCAPES. File/ImageFile
     * carry user-controlled filename/mime/contents — no value-shape guarantee
     * that would justify a blanket escape. Enum used to live here; #908 added
     * an explicit escape entry (the validated value is always one of the
     * developer-declared case backing values — a source-code constant, same
     * provenance / whitelist trust model as Rule::in([...])), so it moves to
     * the positive-assertion test above.
     * If a future refactor added either of these, this test would flip to a
     * non-zero expectation and fail, forcing a deliberate decision.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNonEscapingMappedRuleClasses(): iterable
    {
        yield 'File' => ['Illuminate\\Validation\\Rules\\File'];
        yield 'ImageFile' => ['Illuminate\\Validation\\Rules\\ImageFile'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('provideNonEscapingMappedRuleClasses')]
    public function mapped_rule_class_outside_escape_table_removes_no_taint(string $fqn): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'string', 'class:' . $fqn],
        );

        $this->assertSame(0, $rule->removedTaints);
    }

    #[Test]
    public function class_segment_for_rules_email_is_case_insensitive(): void
    {
        // ValidationRuleAnalyzer lower-cases the FQN for cache/table lookup,
        // so mixed-case input (e.g. from a `resolvedName` that preserves the
        // `use` statement's casing) must still hit the escape table.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['class:illuminate\\validation\\rules\\EMAIL'],
        );

        $this->assertSame(
            TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE,
            $rule->removedTaints,
        );
    }

    // --- lookupRuleByKey (#838 wildcard-suffix fallback) ---

    #[Test]
    public function lookup_by_key_returns_exact_match_when_present(): void
    {
        $emailRule = ValidationRuleAnalyzer::resolveRuleSegments(['required', 'email']);
        $rules = ['email' => $emailRule];

        $this->assertSame($emailRule, ValidationRuleAnalyzer::lookupRuleByKey($rules, 'email'));
    }

    #[Test]
    public function lookup_by_key_returns_null_when_key_missing_and_no_numeric_suffix(): void
    {
        $rules = ['email' => ValidationRuleAnalyzer::resolveRuleSegments(['email'])];

        $this->assertNull(ValidationRuleAnalyzer::lookupRuleByKey($rules, 'phone'));
    }

    #[Test]
    public function lookup_by_key_strips_trailing_numeric_segment_and_retries(): void
    {
        // Simulates `'email.*' => [..., 'email']` after resolveRules expansion:
        // the parent key 'email' holds the element rule, so `input('email.0')`
        // strips `.0` and finds it.
        $parentRule = ValidationRuleAnalyzer::resolveRuleSegments(['required', 'email']);
        $rules = ['email' => $parentRule];

        $this->assertSame($parentRule, ValidationRuleAnalyzer::lookupRuleByKey($rules, 'email.0'));
    }

    #[Test]
    public function lookup_by_key_handles_multi_digit_indices(): void
    {
        // Laravel's dot-notation input indexing allows any non-negative integer.
        $parentRule = ValidationRuleAnalyzer::resolveRuleSegments(['email']);
        $rules = ['email' => $parentRule];

        $this->assertSame($parentRule, ValidationRuleAnalyzer::lookupRuleByKey($rules, 'email.42'));
    }

    #[Test]
    public function lookup_by_key_does_not_strip_non_numeric_suffix(): void
    {
        // `input('email.foo')` is a deliberately different access shape from
        // `input('email.0')` — it typically addresses a nested object, not
        // an array element. The fallback must only rewrite purely numeric
        // trailing segments so nested-wildcard patterns stay out of scope.
        $rules = ['email' => ValidationRuleAnalyzer::resolveRuleSegments(['email'])];

        $this->assertNull(ValidationRuleAnalyzer::lookupRuleByKey($rules, 'email.foo'));
    }

    #[Test]
    public function lookup_by_key_does_not_walk_past_one_segment(): void
    {
        // Nested wildcards (`'addresses.*.email'` accessed as `addresses.0.email`)
        // are explicitly out of scope for #838. `addresses.0.email` does not
        // end in `.\d+`, so the regex fails and no fallback applies. Even the
        // parent `addresses` key would be wrong for this access — the rule
        // describes the `.email` leaf, not the whole address object.
        $rules = ['addresses' => ValidationRuleAnalyzer::resolveRuleSegments(['email'])];

        $this->assertNull(ValidationRuleAnalyzer::lookupRuleByKey($rules, 'addresses.0.email'));
    }

    #[Test]
    public function lookup_by_key_returns_null_when_numeric_suffix_strips_to_missing_parent(): void
    {
        // No 'phone' rule — stripping `.0` from 'phone.0' yields 'phone',
        // which is still missing. Fall through to null.
        $rules = ['email' => ValidationRuleAnalyzer::resolveRuleSegments(['email'])];

        $this->assertNull(ValidationRuleAnalyzer::lookupRuleByKey($rules, 'phone.0'));
    }

    #[Test]
    public function lookup_by_key_prefers_exact_match_over_fallback(): void
    {
        // Contrived but valid: both 'items' (whole) and 'items.0' (specific
        // element) rules coexist. The exact key must win — the fallback is
        // a miss-recovery step, not a competing lookup.
        $exactRule = ValidationRuleAnalyzer::resolveRuleSegments(['string']);
        $parentRule = ValidationRuleAnalyzer::resolveRuleSegments(['email']);
        $rules = [
            'items' => $parentRule,
            'items.0' => $exactRule,
        ];

        $this->assertSame($exactRule, ValidationRuleAnalyzer::lookupRuleByKey($rules, 'items.0'));
    }

    // --- Numeric range narrowing (#1234) ---

    #[Test]
    public function integer_with_min_narrows_to_int_range(): void
    {
        $rule = $this->resolve('integer|min:1');

        $this->assertSame('int<1, max>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_min_zero_narrows_to_int_range(): void
    {
        $rule = $this->resolve('integer|min:0');

        $this->assertSame('int<0, max>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_max_narrows_to_int_range(): void
    {
        $rule = $this->resolve('integer|max:10');

        $this->assertSame('int<min, 10>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_between_narrows_to_int_range(): void
    {
        $rule = $this->resolve('integer|between:0,24');

        $this->assertSame('int<0, 24>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_size_narrows_to_literal_int(): void
    {
        // Collapsed [N, N] → TLiteralInt, not TIntRange(N, N).
        $rule = $this->resolve('integer|size:5');

        $this->assertSame('5|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_gt_narrows_to_int_range(): void
    {
        // gt:0 exclusive → inclusive range starts one above.
        $rule = $this->resolve('integer|gt:0');

        $this->assertSame('int<1, max>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_gte_narrows_to_int_range(): void
    {
        $rule = $this->resolve('integer|gte:0');

        $this->assertSame('int<0, max>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_lt_narrows_to_int_range(): void
    {
        // lt:100 exclusive → inclusive range ends one below.
        $rule = $this->resolve('integer|lt:100');

        $this->assertSame('int<min, 99>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_lte_narrows_to_int_range(): void
    {
        $rule = $this->resolve('integer|lte:100');

        $this->assertSame('int<min, 100>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_min_and_max_narrows_to_int_range(): void
    {
        $rule = $this->resolve('integer|min:1|max:10');

        $this->assertSame('int<1, 10>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function numeric_with_min_narrows_only_the_int_component(): void
    {
        // float/numeric-string siblings untouched — only int gets ranged.
        $rule = $this->resolve('numeric|min:1');

        $this->assertSame('float|int<1, max>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function string_with_min_is_not_narrowed(): void
    {
        // Gate: no integer/numeric rule — 'min' contributes nothing.
        $rule = $this->resolve('string|min:3');

        $this->assertSame('string', $rule->type->getId());
    }

    #[Test]
    public function min_alone_contributes_no_type(): void
    {
        // No type-bearing rule at all — 'min' never narrows on its own.
        $rule = $this->resolve('min:1');

        $this->assertTrue($rule->type->isMixed());
    }

    #[Test]
    public function integer_with_conflicting_min_max_skips_narrowing(): void
    {
        // min:5|max:3 — impossible interval, keep un-ranged.
        $rule = $this->resolve('integer|min:5|max:3');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_non_integer_min_param_skips_narrowing(): void
    {
        // min:1.5 is not an integer literal — contributes no bound.
        $rule = $this->resolve('integer|min:1.5');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_gt_field_reference_skips_narrowing(): void
    {
        // gt/gte/lt/lte can reference a field name — only literals narrow.
        $rule = $this->resolve('integer|gt:other_field');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_negative_min_narrows_to_int_range(): void
    {
        $rule = $this->resolve('integer|min:-5');

        $this->assertSame('int<-5, max>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function range_narrowing_is_order_independent(): void
    {
        $ruleA = $this->resolve('min:1|integer');
        $ruleB = $this->resolve('integer|min:1');

        $this->assertSame($ruleA->type->getId(), $ruleB->type->getId());
        $this->assertSame('int<1, max>|numeric-string', $ruleA->type->getId());
    }

    #[Test]
    public function integer_with_malformed_between_single_param_skips_narrowing(): void
    {
        $rule = $this->resolve('integer|between:5');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_malformed_between_three_params_skips_narrowing(): void
    {
        $rule = $this->resolve('integer|between:1,2,3');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_between_non_integer_component_skips_narrowing(): void
    {
        $rule = $this->resolve('integer|between:1,abc');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_gt_php_int_max_skips_bound(): void
    {
        // No representable N+1 at PHP_INT_MAX — bound dropped.
        $rule = $this->resolve('integer|gt:' . \PHP_INT_MAX);

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function integer_with_lt_php_int_min_skips_bound(): void
    {
        $rule = $this->resolve('integer|lt:' . \PHP_INT_MIN);

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function multiple_min_rules_keep_the_larger_bound(): void
    {
        $rule = $this->resolve('integer|min:1|min:5');

        $this->assertSame('int<5, max>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function multiple_max_rules_keep_the_smaller_bound(): void
    {
        $rule = $this->resolve('integer|max:10|max:3');

        $this->assertSame('int<min, 3>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function pre_existing_literal_ints_are_not_widened_by_an_unrelated_range_gate(): void
    {
        // Regression guard for the exact-class fix (TLiteralInt/TIntRange
        // extend TInt — see applyNumericRangeNarrowing()). Uses boolean+min,
        // not the originally-suggested in:1,2,3, since `in:` never yields
        // TLiteralInt.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['boolean', 'numeric', 'min:0']);

        $this->assertSame('0|1|bool', $rule->type->getId());
    }

    #[Test]
    public function pre_existing_literal_int_from_accepted_rule_is_not_widened(): void
    {
        // Same guard via 'accepted' — single literal, not a pair.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['accepted', 'integer', 'min:1']);

        $this->assertSame('1|true', $rule->type->getId());
    }

    #[Test]
    public function bare_integer_atom_still_narrows_when_no_prior_literal_exists(): void
    {
        // Contrast: bare TInt (no prior literal) still narrows normally.
        $rule = $this->resolve('integer|min:1');

        $this->assertSame('int<1, max>|numeric-string', $rule->type->getId());
    }

    // --- Explicit integer-rule tracking for integer() casts (#1237) ---

    #[Test]
    public function integer_rule_sets_has_integer_rule_flag(): void
    {
        $rule = $this->resolve('integer|min:1');

        $this->assertTrue($rule->hasIntegerRule);
    }

    #[Test]
    public function numeric_rule_alone_does_not_set_has_integer_rule_flag(): void
    {
        // Analyzer-level type narrowing (validated()/input()) is unaffected —
        // raw values aren't cast there, so the range stays sound. Only the
        // handler-level integer() cast needs this flag (see
        // ValidatedTypeHandler::resolveSelfInteger()): (int) "0.5" = 0 falls
        // outside int<1, max>, so 'numeric' alone must not authorize the cast.
        $rule = $this->resolve('numeric|min:1');

        $this->assertFalse($rule->hasIntegerRule);
        $this->assertSame('float|int<1, max>|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function accepted_rule_alone_does_not_set_has_integer_rule_flag(): void
    {
        // (int) "yes" = 0 — the TLiteralInt(1) in accepted's type is not an
        // authoritative cast target on its own.
        $rule = $this->resolve('accepted');

        $this->assertFalse($rule->hasIntegerRule);
    }

    #[Test]
    public function accepted_with_explicit_integer_sets_has_integer_rule_flag(): void
    {
        // accepted|integer IS sound (intersection {1, '1', true} → 1) —
        // the flag follows the explicit 'integer' rule regardless of which
        // rule won the type slot.
        $rule = $this->resolve('accepted|integer');

        $this->assertTrue($rule->hasIntegerRule);
    }

    // --- exclude* rules defeat the presence guarantee (#1237) ---

    #[Test]
    public function exclude_rule_defeats_presence_guarantee(): void
    {
        $rule = $this->resolve('exclude|required|integer|min:1');

        $this->assertTrue($rule->excluded);
        $this->assertFalse($rule->guaranteesPresence());
    }

    #[Test]
    public function exclude_if_rule_defeats_presence_guarantee(): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['exclude_if:other,value', 'required', 'string']);

        $this->assertFalse($rule->guaranteesPresence());
    }

    #[Test]
    public function exclude_unless_rule_defeats_presence_guarantee(): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['exclude_unless:other,value', 'required', 'string']);

        $this->assertFalse($rule->guaranteesPresence());
    }

    #[Test]
    public function exclude_with_rule_defeats_presence_guarantee(): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['exclude_with:other', 'required', 'string']);

        $this->assertFalse($rule->guaranteesPresence());
    }

    #[Test]
    public function exclude_without_rule_defeats_presence_guarantee(): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['exclude_without:other', 'required', 'string']);

        $this->assertFalse($rule->guaranteesPresence());
    }

    #[Test]
    public function required_without_exclude_still_guarantees_presence(): void
    {
        // Contrast: the new exclude gate must not regress the existing feature.
        $rule = $this->resolve('required|string');

        $this->assertFalse($rule->excluded);
        $this->assertTrue($rule->guaranteesPresence());
    }

    // --- Overflow-safe int-literal params (#1237) ---

    #[Test]
    public function min_beyond_php_int_max_contributes_no_bound(): void
    {
        // (int) "9223372036854775808" (PHP_INT_MAX + 1) silently saturates
        // to PHP_INT_MAX via the cast instead of erroring — round-trip
        // rejection catches what the earlier exact-PHP_INT_MAX guard doesn't.
        $rule = $this->resolve('integer|min:9223372036854775808');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function max_beyond_php_int_max_contributes_no_bound(): void
    {
        $rule = $this->resolve('integer|max:9223372036854775808');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function gt_beyond_php_int_max_contributes_no_bound(): void
    {
        $rule = $this->resolve('integer|gt:9223372036854775808');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function lt_beyond_php_int_max_contributes_no_bound(): void
    {
        $rule = $this->resolve('integer|lt:9223372036854775808');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function min_beyond_php_int_min_contributes_no_bound(): void
    {
        $rule = $this->resolve('integer|min:-9223372036854775809');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }

    #[Test]
    public function leading_zero_param_contributes_no_bound(): void
    {
        // Documented, acceptable precision loss — see intLiteralParam().
        $rule = $this->resolve('integer|min:007');

        $this->assertSame('int|numeric-string', $rule->type->getId());
    }
}
