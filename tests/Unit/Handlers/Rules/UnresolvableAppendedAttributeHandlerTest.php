<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\CastInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\CastShape;
use Psalm\LaravelPlugin\Handlers\Rules\UnresolvableAppendedAttributeHandler;
use Psalm\Type;

/**
 * Unit coverage for the pure verdict of {@see UnresolvableAppendedAttributeHandler}. The full
 * registry-driven path cannot be a phpt fixture: warm-up only loads autoloadable model classes
 * (ModelRegistrationHandler's `class_exists(autoload: true)` gate), and a `.phpt` model is not on the
 * autoloader. So the detection logic is split into the pure, table-testable methods exercised here; the
 * end-to-end emission (warm-up through IssueBuffer) is guarded by
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\UnresolvableAppendedAttributeEmissionTest} (a real Psalm
 * subprocess over an autoloadable fixture), and `composer test:app` guards against false positives on a
 * fresh Laravel app.
 */
#[CoversClass(UnresolvableAppendedAttributeHandler::class)]
final class UnresolvableAppendedAttributeHandlerTest extends TestCase
{
    #[Test]
    public function flags_an_append_with_no_backing(): void
    {
        $this->assertSame(['avatar_url'], UnresolvableAppendedAttributeHandler::unresolvedAppends(['avatar_url'], []));
    }

    #[Test]
    public function clears_an_append_backed_by_an_accessor(): void
    {
        $this->assertSame([], UnresolvableAppendedAttributeHandler::unresolvedAppends(['full_name'], ['fullname' => true]));
    }

    #[Test]
    public function matches_the_accessor_identity_separator_and_case_insensitively(): void
    {
        // full_name / fullName / full-name all collapse to the single accessor identity `fullname`,
        // exactly as Eloquent resolves `$model->full_name` through getFullNameAttribute()/fullName().
        $this->assertSame([], UnresolvableAppendedAttributeHandler::unresolvedAppends(
            ['full_name', 'fullName', 'full-name'],
            ['fullname' => true],
        ));
    }

    #[Test]
    public function reports_only_the_unbacked_entries_in_declaration_order(): void
    {
        $this->assertSame(['avatar_url', 'nickname'], UnresolvableAppendedAttributeHandler::unresolvedAppends(
            ['full_name', 'avatar_url', 'nickname'],
            ['fullname' => true],
        ));
    }

    #[Test]
    public function ignores_a_separator_only_append_with_no_accessor_identity(): void
    {
        // accessorPropertyKey('__') collapses to empty — there is no sensible accessor name to expect, so
        // it is left alone rather than guessed at.
        $this->assertSame([], UnresolvableAppendedAttributeHandler::unresolvedAppends(['__'], []));
    }

    #[Test]
    public function returns_empty_for_a_model_without_appends(): void
    {
        $this->assertSame([], UnresolvableAppendedAttributeHandler::unresolvedAppends([], ['x' => true]));
    }

    #[Test]
    public function collects_every_cast_key_regardless_of_shape(): void
    {
        // Any declared cast counts as backing, not only class casts: the registry's CastShape describes
        // the inferred type, not Laravel's isClassCastable() branch (a first-party Castable such as
        // AsCollection::class is shape Primitive), so the rule cannot tell them apart. Counting any cast
        // keeps it false-positive-free — a flagged entry then provably has no cast at all. This pins that
        // a shape filter is NOT reintroduced.
        $casts = [
            'address' => $this->cast(CastShape::CustomCastsAttributes, 'address'),
            'tags' => $this->cast(CastShape::AsCollection, 'tags'),
            'age' => $this->cast(CastShape::Primitive, 'age'),
            'published_at' => $this->cast(CastShape::DateTime, 'published_at'),
            'status' => $this->cast(CastShape::BackedEnum, 'status'),
        ];

        $this->assertSame([
            'address' => true,
            'tags' => true,
            'age' => true,
            'publishedat' => true,
            'status' => true,
        ], UnresolvableAppendedAttributeHandler::castKeys($casts));
    }

    #[Test]
    public function normalizes_cast_column_keys_to_the_accessor_identity(): void
    {
        $this->assertSame(['fullname' => true], UnresolvableAppendedAttributeHandler::castKeys([
            'full_name' => $this->cast(CastShape::Primitive, 'full_name'),
        ]));
    }

    #[Test]
    public function a_cast_backs_a_matching_append(): void
    {
        // The two pure steps composed: a cast on `address` (no column, no accessor) resolves the appended
        // `address`, so it is not flagged.
        $backed = UnresolvableAppendedAttributeHandler::castKeys([
            'address' => $this->cast(CastShape::CustomCastsAttributes, 'address'),
        ]);

        $this->assertSame([], UnresolvableAppendedAttributeHandler::unresolvedAppends(['address'], $backed));
    }

    #[Test]
    public function drops_hidden_appends_before_checking(): void
    {
        // A hidden append is removed by getArrayableItems() before the serialization loop, so it never
        // fatals — it must not be flagged.
        $this->assertSame(
            ['full_name'],
            UnresolvableAppendedAttributeHandler::serializedAppends(['full_name', 'secret'], ['secret'], []),
        );
    }

    #[Test]
    public function keeps_only_visible_appends_when_a_visible_list_is_set(): void
    {
        // A non-empty $visible is an allow-list: an append absent from it is dropped before the loop.
        $this->assertSame(
            ['full_name'],
            UnresolvableAppendedAttributeHandler::serializedAppends(['full_name', 'avatar_url'], [], ['full_name']),
        );
    }

    #[Test]
    public function keeps_all_appends_when_no_hidden_or_visible_is_set(): void
    {
        $this->assertSame(
            ['full_name', 'avatar_url'],
            UnresolvableAppendedAttributeHandler::serializedAppends(['full_name', 'avatar_url'], [], []),
        );
    }

    #[Test]
    public function a_hidden_unbacked_append_is_not_reported(): void
    {
        // End-to-end of the two pure steps: an unbacked append that is also hidden is filtered out, so
        // unresolvedAppends() never sees it.
        $serialized = UnresolvableAppendedAttributeHandler::serializedAppends(['avatar_url'], ['avatar_url'], []);

        $this->assertSame([], UnresolvableAppendedAttributeHandler::unresolvedAppends($serialized, []));
    }

    #[Test]
    public function message_names_both_accessor_spellings_and_the_attribute(): void
    {
        $message = UnresolvableAppendedAttributeHandler::message('App\\Models\\User', 'avatar_url');

        $this->assertStringContainsString("'avatar_url'", $message);
        $this->assertStringContainsString('App\\Models\\User', $message);
        $this->assertStringContainsString('getAvatarUrlAttribute()', $message);
        $this->assertStringContainsString('avatarUrl(): Attribute', $message);
        $this->assertStringContainsString('BadMethodCallException', $message);
    }

    private function cast(CastShape $shape, string $column = 'x'): CastInfo
    {
        return new CastInfo($column, $shape, null, Type::getMixed(), null);
    }
}
