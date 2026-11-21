<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Rules;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    public function ignores_methods_outside_the_mass_assignment_allowlist(): void
    {
        $call = $this->positioned(new StaticCall(new Name('App\\Models\\User'), new Identifier('save')));

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
     * @param array{string, list<string>}|null $expected root method and link names, or null (declined)
     */
    #[Test]
    #[DataProvider('queryChains')]
    public function fresh_query_chain_accepts_only_a_class_named_static_root_and_shape_preserving_links(string $code, ?array $expected): void
    {
        $call = $this->parse($code);
        $this->assertTrue($call instanceof MethodCall || $call instanceof StaticCall);

        $chain = UnknownModelAttributeHandler::freshQueryChain($call);
        $root = $chain[0]->name ?? null;

        $this->assertSame(
            $expected,
            $chain === null || !$root instanceof Identifier ? null : [$root->name, \array_keys($chain[1])],
        );
    }

    /**
     * @return iterable<string, array{string, array{string, list<string>}|null}>
     */
    public static function queryChains(): iterable
    {
        yield 'static forward is its own root' => ['Post::find(1, ["a"])', ['find', []]];
        yield 'query() root' => ['Post::query()->first(["a"])', ['query', ['query']]];
        yield 'static link root and links' => ['Post::where("a", 1)->orderBy("a")->limit(1)->get(["a"])', ['where', ['limit', 'orderby', 'where']]];
        yield 'method names are case-insensitive' => ['Post::QUERY()->WhereIn("a", [1])->get(["a"])', ['QUERY', ['wherein', 'query']]];
        yield 'join link' => ['Post::query()->join("t", "a", "=", "b")->get(["a"])', null];
        yield 'select makes the argument dead' => ['Post::query()->select("a")->get(["b"])', null];
        yield 'conditional closure' => ['Post::query()->when($f, fn ($q) => $q)->get(["a"])', null];
        yield 'local scope or macro' => ['Post::query()->published()->get(["a"])', null];
        yield 'query() is a root only' => ['Post::where("a", 1)->query()->get(["a"])', null];
        yield 'variable receiver' => ['$builder->get(["a"])', null];
        yield 'variable root of a chain' => ['$builder->where("a", 1)->get(["a"])', null];
        yield 'property receiver' => ['$this->query->get(["a"])', null];
        yield 'relation receiver' => ['$user->posts()->get(["a"])', null];
        yield 'new receiver' => ['(new Post())->newQuery()->get(["a"])', null];
        yield 'nullsafe link' => ['Post::query()?->where("a", 1)->get(["a"])', null];
        yield 'dynamic link' => ['Post::query()->$method()->get(["a"])', null];
        yield 'dynamic class' => ['$class::query()->get(["a"])', null];
        yield 'dynamic static method' => ['Post::$method()->get(["a"])', null];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('columnArguments')]
    public function column_literals_keeps_only_plain_literal_columns(string $code, array $expected): void
    {
        $literals = UnknownModelAttributeHandler::columnLiterals($this->parse($code));

        $this->assertSame($expected, \array_map(static fn(\PhpParser\Node\Scalar\String_ $s): string => $s->value, $literals));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function columnArguments(): iterable
    {
        yield 'list of identifiers' => ['["id", "full_name", "_private", "col2"]', ['id', 'full_name', '_private', 'col2']];
        yield 'single string' => ['"name"', ['name']];
        yield 'JSON path keeps the whole literal' => ['["options->theme->color"]', ['options->theme->color']];
        yield 'wildcards' => ['["*", "posts.*"]', []];
        yield 'qualified names' => ['["posts.title", "db.posts.title"]', []];
        yield 'aliases' => ['["title as t", "title AS t", "options->theme as theme"]', []];
        yield 'raw-looking strings' => ['["count(*)", "`title`", "", " title", "title "]', []];
        yield 'non-literals' => ['[$column, DB::raw("x"), "a" . $b, self::COLUMN]', []];
        yield 'unpacked item is skipped alone' => ['[...$more, "kept"]', ['kept']];
        yield 'any explicit key may overwrite an entry' => ['["dropped", 0 => "kept"]', []];
        yield 'a dynamic key may overwrite an entry' => ['["dropped", $offset => "kept"]', []];
        yield 'closure passed as $columns' => ['fn () => null', []];
        yield 'variable' => ['$columns', []];
    }

    #[Test]
    #[DataProvider('scopeStorages')]
    public function scope_may_extend_builder_trusts_only_laravels_soft_deleting_scope_itself(\Psalm\Storage\ClassLikeStorage $storage, bool $expected): void
    {
        $this->assertSame($expected, UnknownModelAttributeHandler::scopeMayExtendBuilder($storage));
    }

    /**
     * @return iterable<string, array{\Psalm\Storage\ClassLikeStorage, bool}>
     */
    public static function scopeStorages(): iterable
    {
        $soft = \Illuminate\Database\Eloquent\SoftDeletingScope::class;

        yield 'SoftDeletingScope itself' => [self::scopeStorage($soft, $soft), false];
        // Inherits extend(), which calls $this->addRestore() and siblings an override may turn into a join.
        yield 'subclass of SoftDeletingScope' => [self::scopeStorage('App\JoiningSoftDeletes', $soft), true];
        yield 'userland extend()' => [self::scopeStorage('App\ExtendingScope', 'App\ExtendingScope'), true];
        yield 'inherited userland extend()' => [self::scopeStorage('App\ChildScope', 'App\BaseScope'), true];
        yield 'scope without extend()' => [self::scopeStorage('App\TeamScope', null), false];
        yield 'extend() outside a Scope' => [self::scopeStorage('App\Macroable', 'App\Macroable', scope: false), false];
        yield 'unpopulated storage' => [self::scopeStorage('App\Unknown', null, populated: false), true];
    }

    private static function scopeStorage(string $name, ?string $extendDeclarer, bool $scope = true, bool $populated = true): \Psalm\Storage\ClassLikeStorage
    {
        $storage = new \Psalm\Storage\ClassLikeStorage($name);
        $storage->populated = $populated;

        if ($scope) {
            $storage->class_implements = [\strtolower(\Illuminate\Database\Eloquent\Scope::class) => \Illuminate\Database\Eloquent\Scope::class];
        }

        if ($extendDeclarer !== null) {
            $storage->declaring_method_ids = ['extend' => new \Psalm\Internal\MethodIdentifier($extendDeclarer, 'extend')];
        }

        return $storage;
    }

    private function parse(string $code): \PhpParser\Node\Expr
    {
        $statements = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $code . ';');
        $statement = $statements[0] ?? null;
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Expression::class, $statement);

        return $statement->expr;
    }

    /**
     * @param array<lowercase-string, string> $declaring
     * @param list<lowercase-string>          $scanned
     */
    #[Test]
    #[DataProvider('overrideVerdicts')]
    public function userland_override_scan_ignores_absent_siblings(?string $called, array $declaring, array $scanned, bool $expected): void
    {
        $trusted = [BelongsToMany::class, Builder::class, Model::class];
        $this->assertSame($expected, UnknownModelAttributeHandler::hasUserlandOverride($called, $declaring, $scanned, $trusted));
    }

    /**
     * Laravel 12.x has no BelongsToMany::createQuietly()/createManyQuietly() (13.x adds them); vendor
     * here is 13.x, so the 12.x shape is pinned as data: scanned names with no declaring class.
     *
     * @return iterable<string, array{?string, array<lowercase-string, string>, list<lowercase-string>, bool}>
     */
    public static function overrideVerdicts(): iterable
    {
        $scanned = ['create', 'createquietly', 'createmany', 'createmanyquietly'];
        $fw = BelongsToMany::class;

        yield '12.x floor: quiet siblings absent, stock create' => [
            'create', ['create' => $fw, 'createmany' => $fw], $scanned, false,
        ];
        yield 'called method absent' => ['create', ['createmany' => $fw], $scanned, true];
        yield 'called method userland' => ['create', ['create' => 'App\\Rel'], $scanned, true];
        yield 'sibling overridden in userland' => [
            'create', ['create' => $fw, 'createmany' => 'App\\Rel'], $scanned, true,
        ];
        yield 'Illuminate-namespaced userland declarer' => [
            'create', ['create' => $fw, 'newmodelinstance' => 'Illuminate\\Review\\ImportBuilder'], ['create', 'newmodelinstance'], true,
        ];
        yield 'no called method (model scan): trusted declarers only' => [
            null, ['fill' => Model::class, 'isfillable' => 'App\\Concerns\\UsesCustomFields'], ['fill', 'isfillable', 'newinstance'], true,
        ];
        yield 'no called method (model scan): stock model' => [
            null, ['fill' => Model::class, 'isfillable' => Model::class], ['fill', 'isfillable', 'newinstance'], false,
        ];
        yield 'absent hook name is ignored' => [
            'create', ['create' => $fw], ['create', 'newmodelinstance'], false,
        ];
    }

    /**
     * @return iterable<string, array{array<non-empty-lowercase-string, true>, list<string>, list<string>}>
     */
    public static function keyVerdicts(): iterable
    {
        $allowed = ['name' => true, 'fullname' => true, 'emailverifiedat' => true, 'settings' => true];

        yield 'a typo is flagged' => [$allowed, ['nmae'], ['nmae']];
        yield 'camelCase matches a collapsed key' => [$allowed, ['fullName'], []];
        yield 'snake_case matches a collapsed key' => [$allowed, ['email_verified_at'], []];
        // A JSON-path write resolves to its base column, which is known → not a typo.
        yield 'a JSON-path key clears via its base column' => [$allowed, ['settings->theme->color'], []];
        // ...but a typo in the base column is still caught, reported with the full original key.
        yield 'a JSON-path key with a base typo is flagged' => [$allowed, ['settingz->theme'], ['settingz->theme']];
        yield 'only the unknown keys survive, in input order' => [$allowed, ['name', 'nmae', 'bogus'], ['nmae', 'bogus']];
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
