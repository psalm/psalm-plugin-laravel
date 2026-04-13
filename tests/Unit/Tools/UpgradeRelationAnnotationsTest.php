<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the UpgradeRelationAnnotations Psalter plugin.
 *
 * We test upgradeDocblock() directly — the public static method that
 * contains the transformation logic — without needing a full Psalm
 * analysis environment.
 */
#[CoversNothing]
final class UpgradeRelationAnnotationsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/psalter/UpgradeRelationAnnotations.php';
    }

    // --- Auto-upgradeable relations ---

    /**
     * @param non-empty-string $input
     * @param non-empty-string $expected
     */
    #[Test]
    #[DataProvider('autoRelationProvider')]
    public function it_adds_self_as_second_type_parameter(string $input, string $expected): void
    {
        $this->assertSame($expected, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string}> */
    public static function autoRelationProvider(): iterable
    {
        $relations = [
            'BelongsTo',
            'BelongsToMany',
            'HasMany',
            'HasOne',
            'MorphMany',
            'MorphOne',
            'MorphTo',
            'MorphToMany',
        ];

        foreach ($relations as $relation) {
            yield "@return {$relation}" => [
                "/**\n * @return {$relation}<User>\n */",
                "/**\n * @return {$relation}<User, self>\n */",
            ];

            yield "@psalm-return {$relation}" => [
                "/**\n * @psalm-return {$relation}<User>\n */",
                "/**\n * @psalm-return {$relation}<User, self>\n */",
            ];
        }
    }

    // --- Already-migrated annotations should be untouched ---

    /**
     * @param non-empty-string $docblock
     */
    #[Test]
    #[DataProvider('unchangedProvider')]
    public function it_leaves_already_migrated_and_manual_annotations_unchanged(string $docblock): void
    {
        $this->assertSame($docblock, \UpgradeRelationAnnotations::upgradeDocblock($docblock));
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function unchangedProvider(): iterable
    {
        // Already has two params — skip.
        yield 'BelongsTo already migrated' => ["/**\n * @return BelongsTo<User, self>\n */"];
        yield 'HasMany already migrated' => ["/**\n * @return HasMany<Post, self>\n */"];

        // Manual-only relations — never touch them.
        yield 'HasManyThrough unchanged' => ["/**\n * @return HasManyThrough<Post>\n */"];
        yield 'HasOneThrough unchanged' => ["/**\n * @return HasOneThrough<Post>\n */"];

        // Non-return annotation — never touch.
        yield '@param annotation untouched' => ["/**\n * @param BelongsTo<User> \$rel\n */"];
        yield '@var annotation untouched' => ["/**\n * @var BelongsTo<User>\n */"];

        // No annotation at all.
        yield 'plain description' => ["/**\n * Get the user.\n */"];
    }

    // --- Edge cases ---

    #[Test]
    public function it_handles_fully_qualified_class_name(): void
    {
        $input = "/**\n * @return \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo<User>\n */";
        $expected = "/**\n * @return \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo<User, self>\n */";

        $this->assertSame($expected, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_handles_nullable_return_type(): void
    {
        $input = "/**\n * @return ?BelongsTo<User>\n */";
        $expected = "/**\n * @return ?BelongsTo<User, self>\n */";

        $this->assertSame($expected, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_handles_union_with_null(): void
    {
        $input = "/**\n * @return BelongsTo<User>|null\n */";
        $expected = "/**\n * @return BelongsTo<User, self>|null\n */";

        $this->assertSame($expected, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_does_not_match_partial_class_names(): void
    {
        // 'MorphOneOrMany' starts with 'MorphOne' — should not be touched.
        $input = "/**\n * @return MorphOneOrMany<User>\n */";

        $this->assertSame($input, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_handles_namespaced_type_argument(): void
    {
        // Fully-qualified model name as the type argument — common in strict codebases.
        $input = "/**\n * @return BelongsTo<App\\Models\\User>\n */";
        $expected = "/**\n * @return BelongsTo<App\\Models\\User, self>\n */";

        $this->assertSame($expected, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_upgrades_both_return_and_psalm_return_in_same_docblock(): void
    {
        // Both annotations in one docblock — common when adding a more precise psalm-return.
        $input = "/**\n * @return BelongsTo<User>\n * @psalm-return BelongsTo<User>\n */";
        $expected = "/**\n * @return BelongsTo<User, self>\n * @psalm-return BelongsTo<User, self>\n */";

        $this->assertSame($expected, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_upgrades_multiple_relations_on_the_same_line(): void
    {
        // Union of two auto-upgradeable relations on a single @return line.
        $input = "/**\n * @return BelongsTo<User>|HasMany<Post>\n */";
        $expected = "/**\n * @return BelongsTo<User, self>|HasMany<Post, self>\n */";

        $this->assertSame($expected, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_handles_single_line_docblock(): void
    {
        // Compact single-line docblock form has no newlines.
        $input = '/** @return BelongsTo<User> */';
        $expected = '/** @return BelongsTo<User, self> */';

        $this->assertSame($expected, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_does_not_transform_prose_containing_return_and_relation(): void
    {
        // A description line saying "will return BelongsTo<User> instance" must not be touched.
        $input = "/**\n * Will return BelongsTo<User> instance.\n */";

        $this->assertSame($input, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_does_not_corrupt_nested_generic_type_arguments(): void
    {
        // Nested generics like HasMany<Collection<Post>> cannot be safely auto-upgraded —
        // the regex stops at the first '>' and would produce HasMany<Collection<Post, self>>,
        // corrupting the annotation. The plugin must leave these untouched.
        $input = "/**\n * @return HasMany<Collection<Post>>\n */";

        $this->assertSame($input, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }

    #[Test]
    public function it_upgrades_only_the_auto_relation_in_a_union_with_a_manual_relation(): void
    {
        // When an @return unions an auto-upgradeable relation with a manual-only one,
        // only the auto side should gain ', self'. The manual side stays untouched.
        $input = "/**\n * @return BelongsTo<User>|HasManyThrough<Post>\n */";
        $expected = "/**\n * @return BelongsTo<User, self>|HasManyThrough<Post>\n */";

        $this->assertSame($expected, \UpgradeRelationAnnotations::upgradeDocblock($input));
    }
}
