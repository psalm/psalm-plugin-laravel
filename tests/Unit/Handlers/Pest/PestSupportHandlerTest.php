<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Pest;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
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
use Psalm\Plugin\EventHandler\Event\BeforeStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;

#[CoversClass(PestSupportHandler::class)]
final class PestSupportHandlerTest extends TestCase
{
    private const FAKE_PATH = '/virtual/pest-test.php';

    protected function setUp(): void
    {
        // Pre-resolve TestCase to PHPUnit\Framework\TestCase so the production
        // resolver's codebase->classOrInterfaceExists() call is never invoked.
        // The fallback path is what we want exercised; the "is Tests\TestCase
        // in scope" lookup needs a real boot we don't have.
        (new \ReflectionProperty(PestSupportHandler::class, 'resolvedTestCaseClass'))
            ->setValue(null, 'PHPUnit\\Framework\\TestCase');
    }

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

        yield 'empty file' => ['<?php', false];

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

        yield 'top-level todo()' => ["<?php\ntodo('implement search');\n", true];

        yield 'top-level dataset()' => ["<?php\ndataset('numbers', [1, 2, 3]);\n", true];

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
    // beforeStatementAnalysis — $this injection
    // ---------------------------------------------------------------

    #[Test]
    public function injects_this_inside_test_closure(): void
    {
        $contents = "<?php\ntest('a', function (): void { \$this->x(); });\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeBeforeStatementEvent(self::FAKE_PATH, $thisOffset);

        PestSupportHandler::beforeStatementAnalysis($event);

        $context = $event->getContext();
        $this->assertArrayHasKey('$this', $context->vars_in_scope);
        $this->assertSame('PHPUnit\\Framework\\TestCase', $this->extractFqcn($context));
        $this->assertSame('PHPUnit\\Framework\\TestCase', $context->self);
    }

    #[Test]
    public function does_not_inject_this_inside_beforeall_closure(): void
    {
        // beforeAll runs in static context per Pest source; $this stays unset, so
        // VariableFetchAnalyzer still raises InvalidScope as the real bug it is.
        $contents = "<?php\nbeforeAll(function (): void { \$this->seed(); });\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeBeforeStatementEvent(self::FAKE_PATH, $thisOffset);

        PestSupportHandler::beforeStatementAnalysis($event);

        $this->assertArrayNotHasKey('$this', $event->getContext()->vars_in_scope);
    }

    #[Test]
    public function does_not_inject_this_inside_describe_body(): void
    {
        $contents = "<?php\ndescribe('group', function (): void { \$this->state = 1; });\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeBeforeStatementEvent(self::FAKE_PATH, $thisOffset);

        PestSupportHandler::beforeStatementAnalysis($event);

        $this->assertArrayNotHasKey('$this', $event->getContext()->vars_in_scope);
    }

    #[Test]
    public function injects_this_inside_nested_it_within_describe(): void
    {
        $contents = <<<'PHP'
            <?php
            describe('group', function (): void {
                it('thing', function (): void { $this->call(); });
            });
            PHP;
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeBeforeStatementEvent(self::FAKE_PATH, $thisOffset);

        PestSupportHandler::beforeStatementAnalysis($event);

        $this->assertArrayHasKey('$this', $event->getContext()->vars_in_scope);
    }

    #[Test]
    public function does_not_override_existing_this(): void
    {
        // If Psalm's own analysis (e.g. Closure::bind inside a test() body) has
        // already populated $this, the handler must not clobber it.
        $contents = "<?php\ntest('a', function (): void { \$this->x(); });\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeBeforeStatementEvent(self::FAKE_PATH, $thisOffset);

        $existing = new \Psalm\Type\Union([new \Psalm\Type\Atomic\TNamedObject('App\\OtherClass')]);
        $event->getContext()->vars_in_scope['$this'] = $existing;

        PestSupportHandler::beforeStatementAnalysis($event);

        $this->assertSame($existing, $event->getContext()->vars_in_scope['$this']);
    }

    #[Test]
    public function skips_non_pest_files_in_statement_analysis(): void
    {
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, "<?php\necho 'plain script';\n");

        $event = $this->makeBeforeStatementEvent(self::FAKE_PATH, 0);

        PestSupportHandler::beforeStatementAnalysis($event);

        $this->assertArrayNotHasKey('$this', $event->getContext()->vars_in_scope);
    }

    // ---------------------------------------------------------------
    // beforeAddIssue — InternalMethod suppression
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

    #[Test]
    public function drops_invalid_scope_inside_binding_closure_range(): void
    {
        // InvalidScope can fire from MethodCallAnalyzer ($this->method() — checks
        // getFQCLN() not vars_in_scope), so $this injection alone does not silence
        // it. beforeAddIssue drops the cosmetic error inside binding ranges.
        $contents = "<?php\ntest('a', function () { \$this->binds(); });\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeBeforeAddEvent(
            new InvalidScope('Use of $this', $this->makeCodeLocation(self::FAKE_PATH, $thisOffset)),
        );

        $this->assertFalse(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function passes_through_invalid_scope_outside_binding_range(): void
    {
        // InvalidScope outside a binding closure (beforeAll body, describe body,
        // or just stray $this in a non-test file marked Pest) stays reported.
        $contents = "<?php\nbeforeAll(function () { \$this->seed(); });\n";
        $this->driveBeforeAnalyzeFile(self::FAKE_PATH, $contents);

        $thisOffset = (int) \strpos($contents, '$this');
        $event = $this->makeBeforeAddEvent(
            new InvalidScope('Use of $this', $this->makeCodeLocation(self::FAKE_PATH, $thisOffset)),
        );

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    /** @return iterable<string, array{string}> */
    public static function pestInternalMethodIdProvider(): iterable
    {
        // Pass the original (mixed-case) method id. MethodIssue::__construct
        // lowercases on store; the handler keys off the stored lowercased form.
        yield 'TestCall::with' => ['Pest\\PendingCalls\\TestCall::with'];
        yield 'UsesCall::in' => ['Pest\\PendingCalls\\UsesCall::in'];
        yield 'BeforeEachCall::group' => ['Pest\\PendingCalls\\BeforeEachCall::group'];
        yield 'AfterEachCall::group' => ['Pest\\PendingCalls\\AfterEachCall::group'];
        yield 'DescribeCall::skip' => ['Pest\\PendingCalls\\DescribeCall::skip'];
        yield 'Mixins\\Expectation::toBe' => ['Pest\\Mixins\\Expectation::toBe'];
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

    private function driveBeforeAnalyzeFile(string $filePath, string $contents): void
    {
        PestSupportHandler::beforeAnalyzeFile($this->makeBeforeFileEvent($filePath, $contents));
    }

    private function extractFqcn(Context $context): string
    {
        $names = [];
        foreach ($context->vars_in_scope['$this']->getAtomicTypes() as $atomic) {
            $names[] = $atomic->getId();
        }

        return \implode('|', $names);
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
        (new \ReflectionProperty(PestSupportHandler::class, 'pestBindingScopes'))->setValue(null, []);
        (new \ReflectionProperty(PestSupportHandler::class, 'resolvedTestCaseClass'))->setValue(null, null);
    }

    private function makeBeforeFileEvent(string $filePath, string $contents): BeforeFileAnalysisEvent
    {
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2));
        $stmts = $parser->parse($contents) ?? [];

        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn($filePath);
        $source->method('getFileName')->willReturn(\basename($filePath));

        // Use a stub Codebase that returns false for classOrInterfaceExists
        // (so the handler falls back to PHPUnit\Framework\TestCase). Tests for
        // the Tests\TestCase preference path would require booting a real codebase;
        // out of scope for unit coverage.
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        return new BeforeFileAnalysisEvent(
            $source,
            new Context(),
            new FileStorage($filePath),
            $codebase,
            $stmts,
        );
    }

    private function makeBeforeStatementEvent(string $filePath, int $stmtStart): BeforeStatementAnalysisEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn($filePath);
        $source->method('getFileName')->willReturn(\basename($filePath));

        $stmt = new Expression(new Variable('this'));
        $stmt->setAttribute('startFilePos', $stmtStart);
        $stmt->setAttribute('endFilePos', $stmtStart);

        return new BeforeStatementAnalysisEvent(
            $stmt,
            new Context(),
            $source,
            (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor(),
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
