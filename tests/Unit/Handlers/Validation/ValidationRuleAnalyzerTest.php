<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Validation\ResolvedRule;
use Psalm\LaravelPlugin\Handlers\Validation\ValidationRuleAnalyzer;

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

        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function string_rule_keeps_all_taint(): void
    {
        $rule = $this->resolve('string');

        $this->assertSame([], $rule->removedTaints);
    }

    #[Test]
    public function uuid_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('uuid');

        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function url_rule_escapes_header_and_cookie(): void
    {
        $rule = $this->resolve('url');

        $this->assertSame([\Psalm\Type\TaintKind::INPUT_HEADER, \Psalm\Type\TaintKind::INPUT_COOKIE], $rule->removedTaints);
    }

    #[Test]
    public function ip_rule_escapes_all_input_except_ssrf(): void
    {
        $rule = $this->resolve('ip');

        $this->assertNotContains(\Psalm\Type\TaintKind::INPUT_SSRF, $rule->removedTaints);
        $this->assertContains(\Psalm\Type\TaintKind::INPUT_HTML, $rule->removedTaints);
        $this->assertContains(\Psalm\Type\TaintKind::INPUT_HEADER, $rule->removedTaints);
    }

    #[Test]
    public function email_rule_escapes_header_and_cookie(): void
    {
        $rule = $this->resolve('email');

        $this->assertSame([\Psalm\Type\TaintKind::INPUT_HEADER, \Psalm\Type\TaintKind::INPUT_COOKIE], $rule->removedTaints);
    }

    #[Test]
    public function in_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('in:a,b,c');

        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function date_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('date');

        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function alpha_num_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('alpha_num');

        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function decimal_rule_returns_numeric_string(): void
    {
        $rule = $this->resolve('decimal:2');

        $this->assertSame('numeric-string', $rule->type->getId());
        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function digits_rule_returns_numeric_string(): void
    {
        $rule = $this->resolve('digits:4');

        $this->assertSame('numeric-string', $rule->type->getId());
        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function accepted_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('accepted');

        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function declined_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('declined');

        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function before_rule_returns_string_and_removes_taint(): void
    {
        $rule = $this->resolve('before:2025-01-01');

        $this->assertSame('string', $rule->type->getId());
        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    #[Test]
    public function date_equals_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('date_equals:today');

        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
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

        $this->assertSame([], $rule->removedTaints);
    }

    #[Test]
    public function image_rule_keeps_all_taint(): void
    {
        $rule = $this->resolve('image');

        $this->assertSame([], $rule->removedTaints);
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

        $this->assertSame([], $rule->removedTaints);
    }

    #[Test]
    public function boolean_rule_removes_all_taint(): void
    {
        $rule = $this->resolve('boolean');

        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
    }

    // --- Array rule format ---

    #[Test]
    public function array_format_rules_resolve_correctly(): void
    {
        // Simulates ['required', 'integer', 'max:100']
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['required', 'integer', 'max:100']);

        $this->assertSame('int|numeric-string', $rule->type->getId());
        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $rule->removedTaints);
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
        $this->assertSame(ValidationRuleAnalyzer::allInputTaints(), $ruleA->removedTaints);
    }

    #[Test]
    public function first_type_bearing_rule_wins(): void
    {
        // First type-bearing rule determines the type (string before integer)
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['string', 'integer']);

        $this->assertSame('string', $rule->type->getId());
    }
}
