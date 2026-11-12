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
use Psalm\LaravelPlugin\Issues\MissingView;
use Psalm\LaravelPlugin\Issues\MissingViewData;
use Psalm\LaravelPlugin\Issues\SerializedQueuedModel;
use Psalm\LaravelPlugin\Issues\UnconfiguredFilesystemDisk;
use Psalm\LaravelPlugin\Issues\UnknownModelAttribute;
use Psalm\StatementsSource;

/**
 * An experimental issue is `info` by default through
 * {@see \Psalm\LaravelPlugin\Internal\ExperimentalIssuePolicy}; once a project enforces it (or the
 * policy entry is deleted), Psalm gates it on `ERROR_LEVEL` alone, like its own issues.
 */
#[CoversNothing]
final class IssueErrorLevelTest extends TestCase
{
    protected function tearDown(): void
    {
        (new \ReflectionClass(Config::class))->getProperty('instance')->setValue(null, null);
    }

    /** @return iterable<string, array{class-string<PluginIssue>, int, 'error'|'info'}> */
    public static function projectLevels(): iterable
    {
        yield 'MissingViewData at errorLevel 7' => [MissingViewData::class, 7, Config::REPORT_INFO];
        yield 'MissingViewData at errorLevel 6' => [MissingViewData::class, 6, Config::REPORT_ERROR];

        foreach ([MissingView::class, UnconfiguredFilesystemDisk::class, SerializedQueuedModel::class, UnknownModelAttribute::class] as $issueClass) {
            $name = $issueClass::getIssueType();
            yield "{$name} at errorLevel 5" => [$issueClass, 5, Config::REPORT_INFO];
            yield "{$name} at errorLevel 4" => [$issueClass, 4, Config::REPORT_ERROR];
        }
    }

    /** @param class-string<PluginIssue> $issueClass */
    #[Test]
    #[DataProvider('projectLevels')]
    public function a_graduated_issue_reports_at_its_error_level_like_a_psalm_issue(string $issueClass, int $projectLevel, string $expected): void
    {
        $config = Config::loadFromXML(
            \dirname(__DIR__, 3),
            '<?xml version="1.0"?><psalm xmlns="https://getpsalm.org/schema/config" errorLevel="' . $projectLevel . '" />',
        );

        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/example.php');
        $source->method('getFileName')->willReturn('example.php');
        $node = new FuncCall(new Name('view'));
        $node->setAttribute('startFilePos', 0);
        $node->setAttribute('endFilePos', 10);

        $issue = new $issueClass('message', new CodeLocation($source, $node));

        $this->assertSame($expected, $config->getReportingLevelForIssue($issue));
    }
}
