<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Pest;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileSource;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\InternalMethod;
use Psalm\Issue\InvalidScope;
use Psalm\Issue\MissingReturnType;
use Psalm\LaravelPlugin\Handlers\Pest\PestSupportHandler;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;

#[CoversClass(PestSupportHandler::class)]
final class PestSupportHandlerTest extends TestCase
{
    private const FAKE_PATH = '/virtual/pest-test.php';

    protected function tearDown(): void
    {
        $this->resetHandlerState();
    }

    // ---------------------------------------------------------------
    // beforeAnalyzeFile — AST-based Pest detection
    // ---------------------------------------------------------------

    /** @return iterable<string, array{string, bool}> */
    public static function pestSignatureProvider(): iterable
    {
        yield 'top-level test() call' => [
            <<<'PHP'
                <?php
                test('user can do a thing', function (): void {
                    $this->get('/');
                });
                PHP,
            true,
        ];

        yield 'top-level it() call' => [
            <<<'PHP'
                <?php
                it('does the thing', function (): void {
                    expect(true)->toBeTrue();
                });
                PHP,
            true,
        ];

        yield 'top-level describe() block' => [
            <<<'PHP'
                <?php
                describe('group', function (): void {
                    it('does a thing', fn () => null);
                });
                PHP,
            true,
        ];

        yield 'top-level uses()->in()' => [
            <<<'PHP'
                <?php
                uses(Tests\TestCase::class)->in('Feature', 'Unit');
                PHP,
            true,
        ];

        yield 'top-level beforeEach()' => [
            <<<'PHP'
                <?php
                beforeEach(function (): void {
                    $this->seed();
                });
                PHP,
            true,
        ];

        yield 'top-level afterAll()' => [
            <<<'PHP'
                <?php
                afterAll(function (): void {});
                PHP,
            true,
        ];

        yield 'top-level pest()->extend() configuration' => [
            <<<'PHP'
                <?php
                pest()->extend(Tests\TestCase::class)->in('Feature');
                PHP,
            true,
        ];

        yield 'top-level expect() invocation' => [
            <<<'PHP'
                <?php
                expect([1, 2, 3])->toContain(2);
                PHP,
            true,
        ];

        yield 'plain PHPUnit TestCase subclass — not Pest' => [
            <<<'PHP'
                <?php
                namespace Tests\Feature;
                use Tests\TestCase;
                final class ExampleTest extends TestCase {
                    public function test_something(): void {
                        $this->assertTrue(true);
                    }
                }
                PHP,
            false,
        ];

        yield 'service class with method named test — not Pest' => [
            <<<'PHP'
                <?php
                namespace App\Services;
                final class Foo {
                    public function bar(): void {
                        $this->test();
                    }
                    private function test(): void {}
                }
                PHP,
            false,
        ];

        yield 'empty file' => ['<?php', false];

        yield 'file with only namespace/use — not Pest' => [
            <<<'PHP'
                <?php
                namespace App;
                use Illuminate\Support\Str;
                PHP,
            false,
        ];

        yield 'namespaced Pest test file (Pest 3 supports this)' => [
            <<<'PHP'
                <?php
                namespace Tests\Feature;
                test('namespaced thing', function (): void {
                    expect(true)->toBeTrue();
                });
                PHP,
            true,
        ];

        yield 'top-level todo() placeholder' => [
            <<<'PHP'
                <?php
                todo('implement search');
                PHP,
            true,
        ];

        yield 'top-level dataset() definition' => [
            <<<'PHP'
                <?php
                dataset('numbers', [1, 2, 3]);
                PHP,
            true,
        ];

        yield 'string containing "test(" — not Pest (AST distinguishes)' => [
            <<<'PHP'
                <?php
                $sql = "SELECT test( 'x' ) FROM ...";
                PHP,
            false,
        ];
    }

    #[Test]
    #[DataProvider('pestSignatureProvider')]
    public function detects_pest_files_by_ast_signature(string $contents, bool $expected): void
    {
        $filePath = '/virtual/' . \uniqid('test_', true) . '.php';
        PestSupportHandler::beforeAnalyzeFile($this->makeBeforeFileEvent($filePath, $contents));

        $this->assertSame($expected, $this->getFileStatus()[$filePath] ?? false);
    }

    #[Test]
    public function caches_negative_result_to_avoid_reanalysis(): void
    {
        $filePath = '/virtual/' . \uniqid('test_', true) . '.php';
        PestSupportHandler::beforeAnalyzeFile($this->makeBeforeFileEvent($filePath, "<?php\necho 'no pest';\n"));

        $this->assertArrayHasKey($filePath, $this->getFileStatus());
        $this->assertFalse($this->getFileStatus()[$filePath]);
    }

    // ---------------------------------------------------------------
    // beforeAddIssue — InvalidScope range-based suppression
    // ---------------------------------------------------------------

    #[Test]
    public function suppresses_invalid_scope_inside_test_closure(): void
    {
        // `$this->x();` sits at byte offset 32 inside the test() callback,
        // well within the closure range Pest binds at runtime.
        $contents = "<?php\ntest('a', function (): void { \$this->x(); });\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeInvalidScopeEvent(self::FAKE_PATH, $thisOffset);

        $this->assertFalse(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function does_not_suppress_invalid_scope_inside_beforeall_closure(): void
    {
        // beforeAll runs in static context per Pest source; `$this` is genuinely invalid
        // there and must remain flagged so users see the real bug.
        $contents = "<?php\nbeforeAll(function (): void { \$this->seed(); });\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeInvalidScopeEvent(self::FAKE_PATH, $thisOffset);

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function does_not_suppress_invalid_scope_inside_describe_closure(): void
    {
        // describe() body is unbound. `$this` here is invalid and stays flagged.
        $contents = "<?php\ndescribe('group', function (): void { \$this->state = 1; });\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeInvalidScopeEvent(self::FAKE_PATH, $thisOffset);

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function suppresses_invalid_scope_inside_nested_it_within_describe(): void
    {
        // Inner it() closure binds to TestCase even though it sits inside an
        // unbound describe() block. Range list must catch the nested binding.
        $contents = <<<'PHP'
            <?php
            describe('group', function (): void {
                it('thing', function (): void { $this->call(); });
            });
            PHP;
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeInvalidScopeEvent(self::FAKE_PATH, $thisOffset);

        $this->assertFalse(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function passes_through_invalid_scope_in_non_pest_file(): void
    {
        $contents = "<?php\necho 'plain script';\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $event = $this->makeInvalidScopeEvent(self::FAKE_PATH, 0);

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    // ---------------------------------------------------------------
    // beforeAddIssue — issue type and method-id dispatch
    // ---------------------------------------------------------------

    #[Test]
    public function passes_through_unrelated_issue_types(): void
    {
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, "<?php\ntest('a', fn () => null);\n");

        $event = $this->makeBeforeAddEvent(
            new MissingReturnType('oops', $this->makeCodeLocation(self::FAKE_PATH, 0)),
        );

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    /** @return iterable<string, array{string}> */
    public static function pestInternalMethodIdProvider(): iterable
    {
        // Pass the original (mixed-case) method id. MethodIssue::__construct
        // lowercases on store; the handler keys off the stored lowercased form.
        // Constructing with mixed case exercises both the framework's lowercasing
        // and the handler's prefix-match logic.
        yield 'TestCall::with' => ['Pest\\PendingCalls\\TestCall::with'];
        yield 'TestCall::skip' => ['Pest\\PendingCalls\\TestCall::skip'];
        yield 'UsesCall::in' => ['Pest\\PendingCalls\\UsesCall::in'];
        yield 'BeforeEachCall::group' => ['Pest\\PendingCalls\\BeforeEachCall::group'];
        yield 'AfterEachCall::group' => ['Pest\\PendingCalls\\AfterEachCall::group'];
        yield 'DescribeCall::skip' => ['Pest\\PendingCalls\\DescribeCall::skip'];
        yield 'Mixins\\Expectation::toBe' => ['Pest\\Mixins\\Expectation::toBe'];
        yield 'Mixins\\Expectation::toContain' => ['Pest\\Mixins\\Expectation::toContain'];
        yield 'Expectations\\HigherOrderExpectation::__call' => ['Pest\\Expectations\\HigherOrderExpectation::__call'];
        yield 'Expectations\\EachExpectation::__call' => ['Pest\\Expectations\\EachExpectation::__call'];
        yield 'Expectations\\OppositeExpectation::toBe' => ['Pest\\Expectations\\OppositeExpectation::toBe'];
        yield 'Configuration::extend' => ['Pest\\Configuration::extend'];
    }

    #[Test]
    #[DataProvider('pestInternalMethodIdProvider')]
    public function drops_internal_method_for_pest_dsl_in_pest_file(string $methodId): void
    {
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, "<?php\ntest('a', fn () => null);\n");

        $event = $this->makeBeforeAddEvent(
            new InternalMethod('internal call', $this->makeCodeLocation(self::FAKE_PATH, 0), $methodId),
        );

        $this->assertFalse(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function passes_through_unrelated_internal_method_in_pest_file(): void
    {
        // A legitimate `@internal` call into a non-Pest namespace from a Pest
        // test file must still be flagged. The suppression is narrow on purpose.
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, "<?php\ntest('a', fn () => null);\n");

        $event = $this->makeBeforeAddEvent(
            new InternalMethod(
                'internal call',
                $this->makeCodeLocation(self::FAKE_PATH, 0),
                'SomePackage\\Internal\\Service::do',
            ),
        );

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function passes_through_pest_internal_method_in_non_pest_file(): void
    {
        // The Pest internal class IS being called — but from a non-Pest file.
        // The surrounding code is presumably not a test, so the @internal
        // warning is legitimate and must reach the user.
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, "<?php\necho 'plain script';\n");

        $event = $this->makeBeforeAddEvent(
            new InternalMethod(
                'internal call',
                $this->makeCodeLocation(self::FAKE_PATH, 0),
                'Pest\\PendingCalls\\TestCall::with',
            ),
        );

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Drive a synthetic file through `beforeAnalyzeFile` so the static
     * detection map is populated as it would be in a real Psalm run.
     */
    private function driveBeforeAnalyzeFile(string $filePath, string $contents): void
    {
        PestSupportHandler::beforeAnalyzeFile($this->makeBeforeFileEvent($filePath, $contents));
    }

    private function makeCodeLocation(string $filePath, int $fileStart): CodeLocation
    {
        $source = $this->createStub(FileSource::class);
        $source->method('getFilePath')->willReturn($filePath);
        $source->method('getFileName')->willReturn(\basename($filePath));

        $node = new FuncCall(new Name('test'), [new Arg(new String_('x'))]);
        $node->setAttribute('startFilePos', $fileStart);
        $node->setAttribute('endFilePos', $fileStart);

        return new CodeLocation($source, $node);
    }

    private function makeInvalidScopeEvent(string $filePath, int $fileStart): BeforeAddIssueEvent
    {
        return $this->makeBeforeAddEvent(
            new InvalidScope('Invalid $this', $this->makeCodeLocation($filePath, $fileStart)),
        );
    }

    /**
     * @return array<string, bool>
     */
    private function getFileStatus(): array
    {
        $prop = new \ReflectionProperty(PestSupportHandler::class, 'pestFileStatus');

        /** @var array<string, bool> $value */
        $value = $prop->getValue();

        return $value;
    }

    private function resetHandlerState(): void
    {
        (new \ReflectionProperty(PestSupportHandler::class, 'pestFileStatus'))->setValue(null, []);
        (new \ReflectionProperty(PestSupportHandler::class, 'pestBindingRanges'))->setValue(null, []);
    }

    private function makeBeforeFileEvent(string $filePath, string $contents): BeforeFileAnalysisEvent
    {
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2));
        $stmts = $parser->parse($contents) ?? [];

        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn($filePath);
        $source->method('getFileName')->willReturn(\basename($filePath));

        // BeforeFileAnalysisEvent's constructor is @internal-tagged but public.
        // The handler reads only $event->getStatementsSource() and ->getStmts(),
        // so the unused parameters are stubbed minimally.
        return new BeforeFileAnalysisEvent(
            $source,
            new Context(),
            new FileStorage($filePath),
            (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor(),
            $stmts,
        );
    }

    private function makeBeforeAddEvent(CodeIssue $issue): BeforeAddIssueEvent
    {
        return new BeforeAddIssueEvent(
            $issue,
            false,
            (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor(),
        );
    }
}
