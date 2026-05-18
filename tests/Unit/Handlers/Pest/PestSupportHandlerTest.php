<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Pest;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\ParserFactory;
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
    protected function tearDown(): void
    {
        $this->resetPestFileMap();
    }

    private function markPestFile(string $filePath): void
    {
        $map = $this->getPestFileMap();
        $map[$filePath] = true;

        (new \ReflectionProperty(PestSupportHandler::class, 'pestFilePaths'))
            ->setValue(null, $map);
    }

    private function resetPestFileMap(): void
    {
        (new \ReflectionProperty(PestSupportHandler::class, 'pestFilePaths'))
            ->setValue(null, []);
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
        $event = $this->makeBeforeFileEvent($filePath, $contents);

        PestSupportHandler::beforeAnalyzeFile($event);

        $marked = isset($this->getPestFileMap()[$filePath]);
        $this->assertSame($expected, $marked);
    }

    // ---------------------------------------------------------------
    // beforeAddIssue — issue suppression
    // ---------------------------------------------------------------

    #[Test]
    public function passes_through_unrelated_issue_types(): void
    {
        // MissingReturnType is neither InvalidScope nor InternalMethod. Even when
        // the surrounding file is a known Pest file, only the two target issues
        // are touched.
        $path = '/virtual/pest-test.php';
        $this->markPestFile($path);

        $event = $this->makeBeforeAddEvent(
            new MissingReturnType('oops', $this->makeCodeLocation($path)),
        );

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function passes_through_invalid_scope_in_non_pest_file(): void
    {
        $event = $this->makeBeforeAddEvent(
            new InvalidScope('Invalid $this', $this->makeCodeLocation('/virtual/plain.php')),
        );

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function drops_invalid_scope_in_pest_file(): void
    {
        $path = '/virtual/pest-test.php';
        $this->markPestFile($path);

        $event = $this->makeBeforeAddEvent(
            new InvalidScope('Invalid $this', $this->makeCodeLocation($path)),
        );

        $this->assertFalse(PestSupportHandler::beforeAddIssue($event));
    }

    /** @return iterable<string, array{string}> */
    public static function pestInternalMethodIdProvider(): iterable
    {
        // Each entry is a real lowercased method_id Pest emits via the DSL.
        // MethodIssue::__construct lowercases on store, so the handler keys
        // off lowercased input. These tests guard the casing invariant.
        yield 'TestCall::with' => ['pest\\pendingcalls\\testcall::with'];
        yield 'TestCall::skip' => ['pest\\pendingcalls\\testcall::skip'];
        yield 'UsesCall::in' => ['pest\\pendingcalls\\usescall::in'];
        yield 'BeforeEachCall::group' => ['pest\\pendingcalls\\beforeeachcall::group'];
        yield 'AfterEachCall::group' => ['pest\\pendingcalls\\aftereachcall::group'];
        yield 'Mixins\\Expectation::toBe' => ['pest\\mixins\\expectation::tobe'];
        yield 'Mixins\\Expectation::toContain' => ['pest\\mixins\\expectation::tocontain'];
        yield 'Expectations\\HigherOrderExpectation::__call' => ['pest\\expectations\\higherorderexpectation::__call'];
        yield 'Expectations\\EachExpectation::__call' => ['pest\\expectations\\eachexpectation::__call'];
        yield 'Expectations\\OppositeExpectation::toBe' => ['pest\\expectations\\oppositeexpectation::tobe'];
    }

    #[Test]
    #[DataProvider('pestInternalMethodIdProvider')]
    public function drops_internal_method_for_pest_dsl_in_pest_file(string $methodId): void
    {
        $path = '/virtual/pest-test.php';
        $this->markPestFile($path);

        $event = $this->makeBeforeAddEvent(
            new InternalMethod('internal call', $this->makeCodeLocation($path), $methodId),
        );

        $this->assertFalse(PestSupportHandler::beforeAddIssue($event));
    }

    #[Test]
    public function passes_through_unrelated_internal_method_in_pest_file(): void
    {
        // A legitimate `@internal` call into a non-Pest namespace from a Pest
        // test file must still be flagged. The suppression is narrow on purpose.
        $path = '/virtual/pest-test.php';
        $this->markPestFile($path);

        $event = $this->makeBeforeAddEvent(
            new InternalMethod(
                'internal call',
                $this->makeCodeLocation($path),
                'somepackage\\internal\\service::do',
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
        $event = $this->makeBeforeAddEvent(
            new InternalMethod(
                'internal call',
                $this->makeCodeLocation('/virtual/plain.php'),
                'pest\\pendingcalls\\testcall::with',
            ),
        );

        $this->assertNull(PestSupportHandler::beforeAddIssue($event));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeCodeLocation(string $filePath): CodeLocation
    {
        $source = $this->createStub(FileSource::class);
        $source->method('getFilePath')->willReturn($filePath);
        $source->method('getFileName')->willReturn(\basename($filePath));

        $node = new FuncCall(new Name('test'), [new Arg(new String_('x'))]);
        $node->setAttribute('startFilePos', 0);
        $node->setAttribute('endFilePos', 10);

        return new CodeLocation($source, $node);
    }

    /**
     * @return array<string, true>
     */
    private function getPestFileMap(): array
    {
        $prop = new \ReflectionProperty(PestSupportHandler::class, 'pestFilePaths');

        /** @var array<string, true> $value */
        $value = $prop->getValue();

        return $value;
    }

    private function makeBeforeFileEvent(string $filePath, string $contents): BeforeFileAnalysisEvent
    {
        $parser = (new ParserFactory())->createForVersion(\PhpParser\PhpVersion::fromComponents(8, 2));
        $stmts = $parser->parse($contents) ?? [];

        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn($filePath);
        $source->method('getFileName')->willReturn(\basename($filePath));

        // BeforeFileAnalysisEvent's constructor is @internal-tagged but public.
        // The handler only reads $event->getStatementsSource() and ->getStmts(),
        // so the unused parameters can be stubbed minimally.
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
        // BeforeAddIssueEvent's constructor takes (CodeIssue, bool, Codebase).
        // The handler never reads the codebase or fixable flag, so we bypass
        // the constructor via reflection and set only the issue.
        $event = (new \ReflectionClass(BeforeAddIssueEvent::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(BeforeAddIssueEvent::class, 'issue'))->setValue($event, $issue);
        (new \ReflectionProperty(BeforeAddIssueEvent::class, 'fixable'))->setValue($event, false);
        (new \ReflectionProperty(BeforeAddIssueEvent::class, 'codebase'))->setValue(
            $event,
            (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor(),
        );

        return $event;
    }
}
