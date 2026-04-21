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
        // Simulates ['required', 'integer', 'max:100']
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['required', 'integer', 'max:100']);

        $this->assertSame('int|numeric-string', $rule->type->getId());
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
     * Defensive guard: classes in RULE_FACADE_METHOD_RETURN_CLASS whose
     * fluent builders return value-shape-unsafe content (user-controlled
     * file uploads, dev-chosen enum cases with runtime-defined string
     * backing) must NOT be in FIRST_PARTY_RULE_ESCAPES. If a future refactor
     * added them, this test would flip to a non-zero expectation and fail.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNonEscapingMappedRuleClasses(): iterable
    {
        yield 'Enum' => ['Illuminate\\Validation\\Rules\\Enum'];
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
}
