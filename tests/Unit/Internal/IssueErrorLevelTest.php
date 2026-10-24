<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Issue\PluginIssue;
use Psalm\LaravelPlugin\Issues\BladeCompileError;
use Psalm\LaravelPlugin\Issues\InvalidViewData;
use Psalm\LaravelPlugin\Issues\MissingViewData;
use Psalm\LaravelPlugin\Issues\UndefinedModelRelation;
use Psalm\LaravelPlugin\Issues\UnknownModelAttribute;
use Psalm\LaravelPlugin\Issues\UnusedViewData;
use Psalm\StatementsSource;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\LoadsPsalmConfigFromXml;

/**
 * Pins the levels the plugin issues graduate to. An experimental issue is `info` by default through
 * {@see \Psalm\LaravelPlugin\Internal\ExperimentalIssuePolicy}; once a project enforces it (or the
 * policy entry is deleted), Psalm gates it on `ERROR_LEVEL` alone, like its own issues.
 */
#[CoversNothing]
final class IssueErrorLevelTest extends TestCase
{
    use LoadsPsalmConfigFromXml;

    protected function tearDown(): void
    {
        (new \ReflectionClass(Config::class))->getProperty('instance')->setValue(null, null);
    }

    /** @return iterable<string, array{class-string<PluginIssue>, int}> */
    public static function levels(): iterable
    {
        // Psalm core analogues: UndefinedMethod (6) for a relation name that is no method,
        // UndefinedMagicPropertyFetch (4) for an attribute the model does not know.
        yield 'MissingViewData' => [MissingViewData::class, 6];
        yield 'InvalidViewData' => [InvalidViewData::class, 6];
        yield 'UnusedViewData' => [UnusedViewData::class, 4];
        yield 'BladeCompileError' => [BladeCompileError::class, -1];
        yield 'UndefinedModelRelation' => [UndefinedModelRelation::class, 6];
        yield 'UnknownModelAttribute' => [UnknownModelAttribute::class, 4];
    }

    /**
     * @param class-string<PluginIssue> $issue
     */
    #[Test]
    #[DataProvider('levels')]
    public function every_issue_declares_its_error_level(string $issue, int $expected): void
    {
        $this->assertSame($issue::ERROR_LEVEL, $expected);
        // An inherited -1 would pass the line above; the level must be a deliberate choice.
        $this->assertSame($issue, (new \ReflectionClassConstant($issue, 'ERROR_LEVEL'))->getDeclaringClass()->getName());
    }

    /** @return iterable<string, array{int, 'error'|'info'}> */
    public static function projectLevels(): iterable
    {
        yield 'errorLevel 7 is more lenient than the issue' => [7, Config::REPORT_INFO];
        yield 'errorLevel 4 is stricter than the issue' => [4, Config::REPORT_ERROR];
    }

    #[Test]
    #[DataProvider('projectLevels')]
    public function a_graduated_issue_reports_at_its_error_level_like_a_psalm_issue(int $projectLevel, string $expected): void
    {
        $config = self::loadPsalmConfigFromXml(
            \dirname(__DIR__, 3),
            '<?xml version="1.0"?><psalm xmlns="https://getpsalm.org/schema/config" errorLevel="' . $projectLevel . '" />',
        );

        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/example.php');
        $source->method('getFileName')->willReturn('example.php');
        $node = new FuncCall(new Name('view'));
        $node->setAttribute('startFilePos', 0);
        $node->setAttribute('endFilePos', 10);

        $issue = new MissingViewData('message', new CodeLocation($source, $node));

        $this->assertSame($expected, $config->getReportingLevelForIssue($issue));
    }
}
