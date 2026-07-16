<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\LaravelPlugin\Handlers\Rules\UnknownModelAttributeHandler;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;

/**
 * Splits like {@see ModelMakeHandlerTest}: the cheap early-return guards run through real
 * {@see AfterExpressionAnalysisEvent}s, and the pure `unknownKeys()` verdict is tested directly (the
 * committed proof a typo is flagged while a normalized / JSON-path / exact match is not). The full
 * schema-gated emission needs migration columns the type-test harness lacks, so it is not
 * unit-testable; `composer test:app` exercises it against a real `users` schema for false-positive
 * regressions only.
 */
#[CoversClass(UnknownModelAttributeHandler::class)]
final class UnknownModelAttributeHandlerTest extends TestCase
{
    #[Test]
    public function ignores_non_call_expressions(): void
    {
        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis(
            $this->event($this->positioned(new Variable('user'))),
        ));
    }

    #[Test]
    public function ignores_methods_outside_the_mass_assignment_allowlist(): void
    {
        $call = $this->positioned(new StaticCall(new Name('App\\Models\\User'), new Identifier('save')));

        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis($this->event($call)));
    }

    #[Test]
    public function ignores_dynamic_method_names(): void
    {
        $call = $this->positioned(new StaticCall(new Name('App\\Models\\User'), new Variable('method')));

        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis($this->event($call)));
    }

    #[Test]
    public function ignores_calls_without_an_attribute_argument(): void
    {
        $call = $this->positioned(new StaticCall(new Name('App\\Models\\User'), new Identifier('create')));

        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis($this->event($call)));
    }

    #[Test]
    public function ignores_argument_unpacking(): void
    {
        // `User::create(...[...])` — a spread argument carries no statically-bound keys, so the unpack
        // guard skips it. resolvedName is set so that removing the guard reaches (and errors on) the
        // receiver path — i.e. the guard is the load-bearing reason this returns null.
        $call = $this->positioned(new StaticCall(
            $this->resolvedModelName(),
            new Identifier('create'),
            [new Arg(new Array_([]), false, true)],
        ));

        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis($this->event($call)));
    }

    #[Test]
    public function ignores_a_named_argument_bound_to_a_different_parameter(): void
    {
        // `User::create(somethingElse: [...])` is not the $attributes map, so it is left alone.
        // resolvedName is set so the named-argument guard is the load-bearing reason for null.
        $call = $this->positioned(new StaticCall(
            $this->resolvedModelName(),
            new Identifier('create'),
            [new Arg(new Array_([]), false, false, [], new Identifier('somethingElse'))],
        ));

        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis($this->event($call)));
    }

    #[Test]
    public function ignores_a_non_literal_attribute_array(): void
    {
        // `User::create($data)` — the keys are not statically known, so the rule never inspects it.
        // Returns before any Codebase lookup, so the uninitialized Codebase below is never touched.
        $call = $this->positioned(new StaticCall(
            new Name('App\\Models\\User'),
            new Identifier('create'),
            [new Arg(new Variable('data'))],
        ));

        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis($this->event($call)));
    }

    #[Test]
    public function ignores_a_static_call_through_an_unresolvable_special_class_name(): void
    {
        // `parent::` is a special class name resolveClassName() deliberately does not resolve (only
        // `self`/`static` are — a static call through the parent class is rare and getFQCLN() would
        // point at the wrong class), so the call is skipped before any Codebase lookup — even though
        // it clears the earlier argument guards with a literal array.
        $call = $this->positioned(new StaticCall(
            new Name('parent'),
            new Identifier('fill'),
            [new Arg(new Array_([]))],
        ));

        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis($this->event($call)));
    }

    #[Test]
    public function ignores_an_instance_call_whose_receiver_type_is_unresolved(): void
    {
        // `$user->fill([...])` where the receiver type is unknown (NodeTypeProvider returns null) is
        // not unambiguously one model, so the rule bails before any Codebase lookup. Locks the
        // instance-dispatch wiring and the unresolved-receiver guard.
        $call = $this->positioned(new MethodCall(
            new Variable('user'),
            new Identifier('fill'),
            [new Arg(new Array_([]))],
        ));

        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis($this->event($call)));
    }

    #[Test]
    public function skips_a_mass_assignment_inside_a_migration_file(): void
    {
        // A create() located in a migration file is left alone: migrations are point-in-time and may
        // reference since-dropped columns (#1251). The array content is irrelevant — the guard fires
        // after the literal-array check but before receiver resolution, so the uninitialised Codebase is
        // never touched (proving the guard is load-bearing, like the sibling early-return tests).
        $call = $this->positioned(new StaticCall(
            $this->resolvedModelName(),
            new Identifier('create'),
            [new Arg(new Array_([]))],
        ));

        $this->assertNull(UnknownModelAttributeHandler::afterExpressionAnalysis(
            $this->event($call, '/project/database/migrations/2024_01_01_000000_create_users_table.php'),
        ));
    }

    #[Test]
    #[DataProvider('migrationFilePaths')]
    public function is_migration_file_guesses_by_filename_or_directory(string $filePath, bool $expected): void
    {
        $this->assertSame($expected, UnknownModelAttributeHandler::isMigrationFile($filePath));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function migrationFilePaths(): iterable
    {
        // Both signals present — the common case.
        yield 'timestamped file in a migrations dir' => ['/app/database/migrations/2024_10_16_120026_move_redis.php', true];
        // Filename signal alone: a migration in a non-standard directory.
        yield 'timestamped filename outside a migrations dir' => ['/app/custom/2014_10_12_000000_create_users_table.php', true];
        // Directory signal alone: a migration whose name lacks the timestamp prefix.
        yield 'non-timestamped file in a migrations dir' => ['/pkg/database/migrations/create_things.php', true];
        // Windows separators still match the directory signal after normalization.
        yield 'windows path in a migrations dir' => ['C:\\proj\\database\\migrations\\2024_01_01_000000_x.php', true];
        // Neither signal: ordinary application code.
        yield 'a model' => ['/app/Models/User.php', false];
        // "Migration" in a filename is not the `migrations` directory, and there is no timestamp prefix.
        yield 'a class merely named Migration' => ['/app/Support/MigrationHelper.php', false];
        // A leading-year filename that is not the full YYYY_MM_DD_HHMMSS_ shape.
        yield 'a partial-timestamp filename' => ['/app/2024_report.php', false];
    }

    /**
     * @param array<non-empty-lowercase-string, true> $allowed
     * @param list<string>                            $rawKeys
     * @param list<string>                            $expected
     */
    #[Test]
    #[DataProvider('keyVerdicts')]
    public function unknown_keys_returns_only_the_unrecognized_raw_keys(array $allowed, array $rawKeys, array $expected): void
    {
        $this->assertSame($expected, UnknownModelAttributeHandler::unknownKeys($allowed, $rawKeys));
    }

    /**
     * @return iterable<string, array{array<non-empty-lowercase-string, true>, list<string>, list<string>}>
     */
    public static function keyVerdicts(): iterable
    {
        $allowed = ['name' => true, 'fullname' => true, 'emailverifiedat' => true, 'settings' => true];

        yield 'a typo is flagged' => [$allowed, ['nmae'], ['nmae']];
        yield 'an exact match is allowed' => [$allowed, ['name'], []];
        yield 'camelCase matches a collapsed key' => [$allowed, ['fullName'], []];
        yield 'snake_case matches a collapsed key' => [$allowed, ['email_verified_at'], []];
        // A JSON-path write resolves to its base column, which is known → not a typo.
        yield 'a JSON-path key clears via its base column' => [$allowed, ['settings->theme->color'], []];
        // ...but a typo in the base column is still caught, reported with the full original key.
        yield 'a JSON-path key with a base typo is flagged' => [$allowed, ['settingz->theme'], ['settingz->theme']];
        yield 'only the unknown keys survive, in input order' => [$allowed, ['name', 'nmae', 'bogus'], ['nmae', 'bogus']];
        yield 'no keys yields nothing' => [$allowed, [], []];
        yield 'an empty allowed set flags every usable key' => [[], ['anything'], ['anything']];
        // A key that normalizes to nothing (only separators) has no identity to validate → not flagged.
        yield 'a separator-only key is treated as known' => [[], ['___'], []];
    }

    private function positioned(\PhpParser\Node\Expr $expr): \PhpParser\Node\Expr
    {
        $expr->setAttribute('startFilePos', 0);
        $expr->setAttribute('endFilePos', 10);

        return $expr;
    }

    /**
     * A class name carrying the `resolvedName` attribute the name resolver would set, so a guard that
     * returns before receiver resolution is load-bearing: dropping it would let the handler reach the
     * receiver/Codebase path and error on the uninitialized Codebase.
     */
    private function resolvedModelName(): Name
    {
        $name = new Name('App\\Models\\User');
        $name->setAttribute('resolvedName', 'App\\Models\\User');

        return $name;
    }

    private function event(\PhpParser\Node\Expr $expr, string $filePath = '/project/app/Models/User.php'): AfterExpressionAnalysisEvent
    {
        // Default the receiver type to null so an instance call resolves to no model; static-call
        // tests never consult the node type provider.
        $nodeTypeProvider = $this->createStub(NodeTypeProvider::class);
        $nodeTypeProvider->method('getType')->willReturn(null);

        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn($filePath);
        $source->method('getFileName')->willReturn('User.php');
        $source->method('getSuppressedIssues')->willReturn([]);
        $source->method('getNodeTypeProvider')->willReturn($nodeTypeProvider);

        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        return new AfterExpressionAnalysisEvent($expr, new Context(), $source, $codebase);
    }
}
