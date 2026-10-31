<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Config\EnvExpressionTyper;
use Tests\Psalm\LaravelPlugin\Unit\Util\Ast\Concerns\InitializesPsalmConfigSingleton;

#[CoversClass(EnvExpressionTyper::class)]
final class EnvExpressionTyperTest extends TestCase
{
    use InitializesPsalmConfigSingleton;

    #[Test]
    #[DataProvider('typedExpressions')]
    public function types_an_env_reading_expression(string $expression, string $expected): void
    {
        $this->assertSame($expected, EnvExpressionTyper::type($this->parse($expression))->getId());
    }

    /** @return iterable<string, array{string, string}> */
    public static function typedExpressions(): iterable
    {
        yield 'env without default' => ["env('K')", 'null|string'];
        yield 'env with null default' => ["env('K', null)", 'null|string'];
        yield 'Env::get without default' => ["\\Illuminate\\Support\\Env::get('K')", 'null|string'];
        yield 'Env::getOrFail' => ["\\Illuminate\\Support\\Env::getOrFail('K')", 'null|string'];
        yield 'string default' => ["env('K', 'lit')", 'string'];
        yield 'int default' => ["env('K', 8080)", 'int|string'];
        yield 'negative int default' => ["env('K', -1)", 'int|string'];
        yield 'float default' => ["env('K', 1.5)", 'float|string'];
        yield 'bool default' => ["env('K', false)", 'bool|string'];
        yield 'class-string default' => ["env('K', \\stdClass::class)", 'string'];
        yield 'nested env default' => ["env('A', env('B', 8080))", 'int|string'];
        yield 'nullable nested env default' => ["env('A', env('B'))", 'null|string'];
        yield 'arrow fn default' => ["env('K', fn () => 8080)", 'int|string'];
        yield 'arrow fn default returning a closure' => ["env('K', fn () => fn (): int => 42)", 'Closure|string'];
        yield 'empty array default' => ["env('K', [])", 'array<never, never>|string'];
        yield 'array default' => ["env('K', ['a', 'b'])", 'list{string, string}|string'];
        yield 'constant default' => ["env('K', LOG_USER)", 'int|string'];
        yield 'int arithmetic default' => ["env('K', 60 * 60)", 'int|string'];
        yield 'float arithmetic default' => ["env('K', 0.5 * 60)", 'float|string'];
        yield 'numeric string arithmetic default' => ["env('K', '60' * 60)", 'int|string'];
        yield 'overflowing arithmetic default' => ["env('K', PHP_INT_MAX * 2)", 'float|string'];
        yield 'arithmetic default that may overflow' => ["env('K', (int) env('A') * 60)", 'float|int|string'];
        yield 'path helper default' => ["env('K', database_path('db.sqlite'))", 'string'];
        yield 'realpath default' => ["env('K', realpath(storage_path('views')))", 'false|string'];
        yield 'bool cast' => ["(bool) env('K')", 'bool'];
        yield 'int cast' => ["(int) env('K', 30)", 'int'];
        yield 'float cast' => ["(float) env('K')", 'float'];
        yield 'string cast' => ["(string) env('K')", 'string'];
        yield 'array cast' => ["(array) env('K')", 'array<array-key, mixed>'];
        yield 'concat' => ["env('K', 'app') . '_cache'", 'string'];
        yield 'interpolation' => ['"v{$_ENV[\'K\']}"', 'string'];
        yield 'comparison' => ["env('K') === 'x'", 'bool'];
        yield 'negation' => ["!env('K')", 'bool'];
        yield 'logical and' => ["env('A') && env('B')", 'bool'];
        yield 'isset' => ["isset(\$_ENV['K'])", 'bool'];
        yield 'empty' => ["empty(\$_ENV['K'])", 'bool'];
        yield 'instanceof' => ["env('K') instanceof \\stdClass", 'bool'];
        yield 'coalesce drops null from the left' => ["env('K') ?? 8080", 'int|string'];
        yield 'short ternary drops null and false from the left' => ["env('K', false) ?: 8080", 'int|string|true'];
        yield 'ternary unites both branches' => ["env('K') ? 1 : 'x'", 'int|string'];
        yield 'explode' => ["explode(',', env('K', 'a'))", 'list<string>'];
        yield 'getenv' => ["getenv('K')", 'false|string'];
    }

    #[Test]
    #[DataProvider('producibleExpressions')]
    public function a_set_variable_can_also_produce_a_bool_or_null(string $expression, string $expected): void
    {
        $this->assertSame($expected, EnvExpressionTyper::producible($this->parse($expression))->getId());
    }

    /** @return iterable<string, array{string, string}> */
    public static function producibleExpressions(): iterable
    {
        yield 'env without default' => ["env('K')", 'bool|null|string'];
        yield 'string default' => ["env('K', 'lit')", 'bool|null|string'];
        yield 'int default' => ["env('K', 8080)", 'bool|int|null|string'];
        yield 'Env::getOrFail' => ["\\Illuminate\\Support\\Env::getOrFail('K')", 'bool|null|string'];
        yield 'short ternary' => ["env('K') ?: 8080", 'int|string|true'];
        yield 'untypeable default' => ["env('K', parse_url('https://a', PHP_URL_HOST))", 'mixed'];
        yield 'cast' => ["(int) env('K')", 'int'];
    }

    #[Test]
    #[DataProvider('unmodelledExpressions')]
    public function types_an_unmodelled_shape_as_mixed(string $expression, string $prelude = ''): void
    {
        $this->assertSame('mixed', EnvExpressionTyper::type($this->parse($expression, $prelude))->getId());
    }

    /** @return iterable<string, array{0: string, 1?: string}> */
    public static function unmodelledExpressions(): iterable
    {
        yield 'unknown call' => ["filter_var(env('K'), FILTER_VALIDATE_BOOLEAN)"];
        yield 'untypeable default' => ["env('K', parse_url('https://a', PHP_URL_HOST))"];
        yield 'statement closure default' => ["env('K', function () { return 1; })"];
        yield 'array default with an untypeable item' => ["env('K', [parse_url('https://a', PHP_URL_HOST)])"];
        yield 'user constant default' => ["env('K', PHPUNIT_COMPOSER_INSTALL)"];
        yield 'undefined constant default' => ["env('K', PSALM_LARAVEL_UNDEFINED)"];
        // Reading it raises E_DEPRECATED, which Psalm's error handler turns into an exception.
        yield 'deprecated constant default' => ["env('K', FILTER_SANITIZE_STRING)"];
        yield 'arithmetic with a non-numeric string operand' => ["env('K', 'abc' * 60)"];
        // A namespaced function of the same name may run instead.
        yield 'unqualified path helper in a namespace' => ["\\env('K', database_path('db.sqlite'))", 'namespace PsalmLaravelConfig;'];
        yield 'superglobal' => ["\$_ENV['K']"];
        yield 'variable' => ['$token'];
        yield 'coalesce with an untypeable side' => ["\$_ENV['K'] ?? 'x'"];
        yield 'spaceship is an int' => ["env('K') <=> 'x'"];
        yield 'named arguments' => ["env(key: 'K', default: 1)"];
        yield 'unpacked arguments' => ['env(...$args)'];
        yield 'first-class callable' => ['env(...)'];
        yield 'other Env method' => ["\\Illuminate\\Support\\Env::getRepository()"];
    }

    #[Test]
    #[DataProvider('envReads')]
    public function detects_an_env_read(string $expression, ?bool $expected, string $prelude = ''): void
    {
        $this->assertSame($expected, EnvExpressionTyper::readsEnv($this->parse($expression, $prelude)));
    }

    /** @return iterable<string, array{0: string, 1: ?bool, 2?: string}> */
    public static function envReads(): iterable
    {
        yield 'env' => ["env('K')", true];
        yield 'fully qualified env' => ["\\env('K')", true];
        yield 'getenv' => ["getenv('K')", true];
        yield 'Env facade class' => ["\\Illuminate\\Support\\Env::get('K')", true];
        yield 'imported Env class' => ["Env::get('K')", true, 'use Illuminate\Support\Env;'];
        yield 'superglobal' => ["\$_SERVER['K']", true];
        yield 'fully qualified env in a namespace' => ["\\env('K')", true, 'namespace PsalmLaravelConfig;'];
        yield 'imported env in a namespace' => ["env('K')", true, 'namespace PsalmLaravelConfig; use function env;'];
        yield 'invoked closure' => ["value(fn () => env('K'))", true];
        yield 'immediately invoked arrow fn reading env' => ["(fn () => env('K'))()", true];
        yield 'spread' => ["[...explode(',', env('K'))]", true];
        yield 'literal' => ["'value'", false];
        yield 'env-free call' => ["storage_path('app')", false];
        yield 'variable' => ['$token', false];
        // Their bodies are searched like any other subexpression.
        yield 'immediately invoked closure' => ["(function (): array { return ['a' => 1]; })()", false];
        yield 'immediately invoked arrow fn' => ['(fn () => 5)()', false];
        // The callee may be `env(...)` or 'env'.
        yield 'call through a variable' => ["\$read('K')", true];
        yield 'stored closure' => ["fn () => env('K')", false];
        yield 'first-class callable' => ['env(...)', false];
        yield 'Env first-class callable' => ["\\Illuminate\\Support\\Env::get(...)", false];
        yield 'unqualified first-class callable in a namespace' => ['env(...)', false, 'namespace PsalmLaravelConfig;'];
        yield 'imported namespaced env' => ["env('K')", false, 'use function Vendor\Package\env;'];
        yield 'aliased function named env' => ["env('K')", false, 'use function strtolower as env;'];
        // At runtime these fall back to the global function only when no namespaced one exists by then.
        yield 'unqualified env in a namespace' => ["env('K')", null, 'namespace PsalmLaravelConfig;'];
        yield 'unqualified getenv in a namespace' => ["getenv('K')", null, 'namespace PsalmLaravelConfig;'];
        // `\Env` may be a facade alias of Laravel's class.
        yield "Env class that is not Laravel's" => ["Env::get('K')", null];
    }

    private function parse(string $expression, string $prelude = ''): Expr
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php {$prelude} return {$expression};");
        $this->assertNotNull($statements);
        (new NodeTraverser(new NameResolver()))->traverse($statements);

        $return = (new NodeFinder())->findFirstInstanceOf($statements, Return_::class);
        $this->assertInstanceOf(Return_::class, $return);
        $this->assertInstanceOf(Expr::class, $return->expr);

        return $return->expr;
    }
}
