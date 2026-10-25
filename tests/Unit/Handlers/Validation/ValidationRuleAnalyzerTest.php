<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{string, string}> */
    public static function provideTypes(): iterable
    {
        yield 'string' => ['required|string', 'string'];
        yield 'integer' => ['required|integer', 'int|numeric-string'];
        yield 'numeric' => ['numeric', 'float|int|numeric-string'];
        yield 'list' => ['list', 'list<mixed>'];
        yield 'file' => ['file', 'Illuminate\\Http\\UploadedFile'];
        yield 'decimal' => ['decimal:2', 'numeric-string'];
        yield 'unknown rule' => ['required|max:255', 'mixed'];
    }

    #[Test]
    #[DataProvider('provideTypes')]
    public function rule_resolves_to_type(string $rules, string $expectedType): void
    {
        $this->assertSame($expectedType, $this->resolve($rules)->type->getId());
    }

    #[Test]
    public function nullable_and_sometimes_modifiers(): void
    {
        $rule = $this->resolve('nullable|sometimes|integer');

        $this->assertTrue($rule->type->hasType('null'));
        $this->assertTrue($rule->type->hasType('int'));
        $this->assertTrue($rule->nullable);
        $this->assertTrue($rule->sometimes);
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function provideRequired(): iterable
    {
        yield 'required' => [['required', 'string'], true];
        yield 'present' => [['present', 'string'], true];
        yield 'accepted implies required' => [['accepted'], true];
        yield 'conditional required' => [['required_if:role,admin', 'string'], false];
        yield 'conditional accepted' => [['accepted_if:role,admin'], false];
        yield 'no presence rule' => [['string', 'max:255'], false];
    }

    /** @param list<string> $segments */
    #[Test]
    #[DataProvider('provideRequired')]
    public function required_flag(array $segments, bool $expected): void
    {
        $this->assertSame($expected, ValidationRuleAnalyzer::resolveRuleSegments($segments)->required);
    }

    /** @return iterable<string, array{string, int}> */
    public static function provideRemovedTaints(): iterable
    {
        yield 'integer' => ['integer', TaintKind::ALL_INPUT];
        yield 'string keeps taint' => ['string', 0];
        yield 'file keeps taint' => ['file', 0];
        yield 'json keeps taint' => ['json', 0];
        yield 'url' => ['url', TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE];
        yield 'ip keeps ssrf' => ['ip', TaintKind::ALL_INPUT & ~TaintKind::INPUT_SSRF];
        yield 'before' => ['before:2025-01-01', TaintKind::ALL_INPUT];
    }

    #[Test]
    #[DataProvider('provideRemovedTaints')]
    public function rule_removes_taint(string $rules, int $expected): void
    {
        $this->assertSame($expected, $this->resolve($rules)->removedTaints);
    }

    #[Test]
    public function rule_order_decides_type_but_not_taint(): void
    {
        $stringFirst = ValidationRuleAnalyzer::resolveRuleSegments(['string', 'integer']);
        $integerFirst = ValidationRuleAnalyzer::resolveRuleSegments(['integer', 'string']);

        $this->assertSame('string', $stringFirst->type->getId());
        $this->assertSame(TaintKind::ALL_INPUT, $stringFirst->removedTaints);
        $this->assertSame(TaintKind::ALL_INPUT, $integerFirst->removedTaints);
    }

    // --- Custom Rule class segments (#822, #828) ---

    #[Test]
    public function class_segment_for_unknown_class_is_tolerated_and_not_type_bearing(): void
    {
        // Without a Psalm analysis context the class storage lookup fails and the segment contributes 0.
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'string', 'class:App\\Rules\\NonExistent'],
        );

        $this->assertSame('string', $rule->type->getId());
        $this->assertSame(0, $rule->removedTaints);
        $this->assertTrue($rule->required);

        $this->assertTrue(ValidationRuleAnalyzer::resolveRuleSegments(['class:App\\Rules\\NonExistent'])->type->isMixed());
    }

    /** @return iterable<string, array{string, int}> */
    public static function provideClassSegmentTaints(): iterable
    {
        yield 'Email' => ['Illuminate\\Validation\\Rules\\Email', TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE];
        yield 'Enum' => ['Illuminate\\Validation\\Rules\\Enum', TaintKind::ALL_INPUT];
        // Blocklists do not constrain the accepted shape, so NotIn is deliberately absent from the escape table.
        yield 'NotIn' => ['Illuminate\\Validation\\Rules\\NotIn', 0];
        // File/ImageFile carry user-controlled filename/mime/contents: a future escape entry must be a deliberate decision.
        yield 'File' => ['Illuminate\\Validation\\Rules\\File', 0];
        // The FQN is lower-cased for the table lookup.
        yield 'mixed case' => ['illuminate\\validation\\rules\\EMAIL', TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE];
    }

    #[Test]
    #[DataProvider('provideClassSegmentTaints')]
    public function first_party_class_segment_taint(string $fqn, int $expected): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['required', 'class:' . $fqn]);

        $this->assertSame($expected, $rule->removedTaints);
    }

    // --- Closure rule escape segments (#1351) ---

    #[Test]
    public function escape_bits_segment_unions_with_string_rule_escapes_without_bearing_type(): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(
            ['required', 'email', 'escape-bits:' . TaintKind::INPUT_CALLABLE],
        );

        $this->assertSame(
            TaintKind::INPUT_HEADER | TaintKind::INPUT_COOKIE | TaintKind::INPUT_CALLABLE,
            $rule->removedTaints,
        );

        $alone = ValidationRuleAnalyzer::resolveRuleSegments(['escape-bits:' . TaintKind::INPUT_HTML]);

        $this->assertTrue($alone->type->isMixed());
        $this->assertFalse($alone->required);
        $this->assertSame(TaintKind::INPUT_HTML, $alone->removedTaints);
    }

    /**
     * `removedTaints` is applied as `taints & ~removedTaints`, so a negative payload such as `-1` would
     * erase every kind at once; anything non-canonical must degrade to a no-op.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedEscapeBitsPayloads(): iterable
    {
        yield 'negative' => ['escape-bits:-1'];
        yield 'signed positive' => ['escape-bits:+8'];
        yield 'trailing junk' => ['escape-bits:123junk'];
        yield 'empty payload' => ['escape-bits:'];
    }

    #[Test]
    #[DataProvider('provideMalformedEscapeBitsPayloads')]
    public function malformed_escape_bits_segment_contributes_no_taint_escape(string $segment): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments(['required', 'string', $segment]);

        $this->assertSame(0, $rule->removedTaints);
        $this->assertSame('string', $rule->type->getId());
        $this->assertTrue($rule->required);
    }

    // --- lookupRuleByKey (#838 wildcard-suffix fallback) ---

    #[Test]
    public function lookup_by_key_strips_trailing_numeric_segment_only_on_a_miss(): void
    {
        $parent = ValidationRuleAnalyzer::resolveRuleSegments(['email']);
        $exact = ValidationRuleAnalyzer::resolveRuleSegments(['string']);
        $rules = ['email' => $parent, 'items' => $parent, 'items.0' => $exact];

        $this->assertSame($parent, ValidationRuleAnalyzer::lookupRuleByKey($rules, 'email.42'));
        $this->assertSame($exact, ValidationRuleAnalyzer::lookupRuleByKey($rules, 'items.0'));
    }

    /** @return iterable<string, array{string}> */
    public static function provideUnresolvableLookupKeys(): iterable
    {
        yield 'missing key' => ['phone'];
        yield 'non-numeric suffix' => ['email.foo'];
        yield 'numeric suffix on missing parent' => ['phone.0'];
        // Nested wildcards are out of scope: the parent rule describes the leaf, not the whole object.
        yield 'nested wildcard' => ['addresses.0.email'];
    }

    #[Test]
    #[DataProvider('provideUnresolvableLookupKeys')]
    public function lookup_by_key_returns_null(string $key): void
    {
        $rules = [
            'email' => ValidationRuleAnalyzer::resolveRuleSegments(['email']),
            'addresses' => ValidationRuleAnalyzer::resolveRuleSegments(['email']),
        ];

        $this->assertNull(ValidationRuleAnalyzer::lookupRuleByKey($rules, $key));
    }

    // --- Numeric range narrowing (#1234); happy paths live in ValidationRuleNumericRangeTest.phpt ---

    /** @return iterable<string, array{string, string}> */
    public static function provideRangeNarrowing(): iterable
    {
        yield 'size collapses to literal' => ['integer|size:5', '5|numeric-string'];
        yield 'gt is exclusive' => ['integer|gt:0', 'int<1, max>|numeric-string'];
        yield 'lt is exclusive' => ['integer|lt:100', 'int<min, 99>|numeric-string'];
        yield 'min and max' => ['integer|min:1|max:10', 'int<1, 10>|numeric-string'];
        yield 'numeric narrows only the int component' => ['numeric|min:1', 'float|int<1, max>|numeric-string'];
        yield 'order independent' => ['min:1|integer', 'int<1, max>|numeric-string'];
        yield 'larger min wins' => ['integer|min:1|min:5', 'int<5, max>|numeric-string'];
        yield 'smaller max wins' => ['integer|max:10|max:3', 'int<min, 3>|numeric-string'];
        yield 'no integer gate' => ['string|min:3', 'string'];
        yield 'min alone' => ['min:1', 'mixed'];
        yield 'conflicting bounds' => ['integer|min:5|max:3', 'int|numeric-string'];
        yield 'non-integer param' => ['integer|min:1.5', 'int|numeric-string'];
        yield 'field reference' => ['integer|gt:other_field', 'int|numeric-string'];
        yield 'malformed between' => ['integer|between:1,2,3', 'int|numeric-string'];
        yield 'gt PHP_INT_MAX has no N+1' => ['integer|gt:' . \PHP_INT_MAX, 'int|numeric-string'];
        // Casting an overflowing literal saturates silently; round-trip rejection must catch it.
        yield 'overflowing literal' => ['integer|min:9223372036854775808', 'int|numeric-string'];
        yield 'leading zero' => ['integer|min:007', 'int|numeric-string'];
    }

    #[Test]
    #[DataProvider('provideRangeNarrowing')]
    public function numeric_range_narrowing(string $rules, string $expectedType): void
    {
        $this->assertSame($expectedType, $this->resolve($rules)->type->getId());
    }

    #[Test]
    public function range_narrowing_does_not_widen_pre_existing_literal_ints(): void
    {
        // TLiteralInt/TIntRange extend TInt; the range gate must match the exact class only.
        $this->assertSame('0|1|bool', $this->resolve('boolean|numeric|min:0')->type->getId());
        $this->assertSame('1|true', $this->resolve('accepted|integer|min:1')->type->getId());
    }

    // --- Rule flags consumed by handlers (#1237, #1234) ---

    /** @return iterable<string, array{string, bool}> */
    public static function provideHasIntegerRule(): iterable
    {
        yield 'integer' => ['integer|min:1', true];
        // (int) "0.5" = 0 falls outside int<1, max>, so 'numeric' alone must not authorize integer() casts.
        yield 'numeric' => ['numeric|min:1', false];
        yield 'accepted alone' => ['accepted', false];
        yield 'accepted with integer' => ['accepted|integer', true];
    }

    #[Test]
    #[DataProvider('provideHasIntegerRule')]
    public function has_integer_rule_flag(string $rules, bool $expected): void
    {
        $this->assertSame($expected, $this->resolve($rules)->hasIntegerRule);
    }

    #[Test]
    public function exclude_family_defeats_presence_guarantee(): void
    {
        $this->assertTrue($this->resolve('required|string')->guaranteesPresence());

        $plain = $this->resolve('exclude|required|integer|min:1');
        $this->assertTrue($plain->excluded);
        $this->assertFalse($plain->guaranteesPresence());

        $conditional = ValidationRuleAnalyzer::resolveRuleSegments(['exclude_if:other,value', 'required', 'string']);
        $this->assertFalse($conditional->guaranteesPresence());
    }

    /** @return iterable<string, array{list<string>, bool, bool}> */
    public static function provideAcceptedDeclinedFlags(): iterable
    {
        yield 'accepted' => [['required', 'accepted'], true, false];
        yield 'declined' => [['required', 'declined'], false, true];
        // Conditional variants depend on runtime values, so no literal is guaranteed.
        yield 'accepted_if' => [['accepted_if:plan,pro'], false, false];
        yield 'boolean' => [['required', 'boolean'], false, false];
        yield 'both' => [['required', 'accepted', 'declined'], true, true];
    }

    /** @param list<string> $segments */
    #[Test]
    #[DataProvider('provideAcceptedDeclinedFlags')]
    public function accepted_and_declined_flags(array $segments, bool $accepted, bool $declined): void
    {
        $rule = ValidationRuleAnalyzer::resolveRuleSegments($segments);

        $this->assertSame($accepted, $rule->hasAcceptedRule);
        $this->assertSame($declined, $rule->hasDeclinedRule);
    }
}
