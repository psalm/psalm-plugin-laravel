<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Config;
use Psalm\LaravelPlugin\Internal\ExperimentalIssuePolicy;

#[CoversClass(ExperimentalIssuePolicy::class)]
final class ExperimentalIssuePolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        (new \ReflectionClass(Config::class))->getProperty('instance')->setValue(null, null);
    }

    /**
     * @return iterable<string, array{bool, 'error'|'info'}>
     */
    public static function enforcementModes(): iterable
    {
        yield 'advisory by default' => [false, Config::REPORT_INFO];
        yield 'enforced when enabled' => [true, Config::REPORT_ERROR];
    }

    #[Test]
    #[DataProvider('enforcementModes')]
    public function it_sets_defaults_for_every_experimental_issue(bool $enforced, string $expectedLevel): void
    {
        $config = $this->loadConfig();

        ExperimentalIssuePolicy::apply($enforced);

        foreach (['UnknownModelAttribute', 'UndefinedModelRelation'] as $issueType) {
            $this->assertSame($expectedLevel, $config->getReportingLevelForFile($issueType, __FILE__));
        }
    }

    #[Test]
    #[DataProvider('enforcementModes')]
    public function explicit_issue_levels_always_win(bool $enforced, string $_expectedDefaultLevel): void
    {
        foreach ([Config::REPORT_ERROR, Config::REPORT_INFO, Config::REPORT_SUPPRESS] as $level) {
            $config = $this->loadConfig(
                '<issueHandlers><PluginIssue name="UnknownModelAttribute" errorLevel="' . $level . '" /></issueHandlers>',
            );

            ExperimentalIssuePolicy::apply($enforced);

            $this->assertSame($level, $config->getReportingLevelForFile('UnknownModelAttribute', __FILE__));
        }
    }

    #[Test]
    #[DataProvider('enforcementModes')]
    public function explicit_global_levels_remain_in_effect_alongside_scoped_filters(bool $enforced, string $_expectedDefaultLevel): void
    {
        $config = $this->loadExplicitErrorAndFilterConfig();

        ExperimentalIssuePolicy::apply($enforced);

        $this->assertSame(Config::REPORT_SUPPRESS, $config->getReportingLevelForFile('UndefinedModelRelation', __FILE__));
        $this->assertSame(Config::REPORT_ERROR, $config->getReportingLevelForFile('UndefinedModelRelation', \dirname(__DIR__, 3) . '/src/Elsewhere.php'));
    }

    private function loadConfig(string $body = ''): Config
    {
        return Config::loadFromXML(
            \dirname(__DIR__, 3),
            '<?xml version="1.0"?><psalm xmlns="https://getpsalm.org/schema/config">' . $body . '</psalm>',
        );
    }

    private function loadExplicitErrorAndFilterConfig(): Config
    {
        $path = __DIR__ . '/Fixtures/ExperimentalIssuePolicy/explicit-error-and-filter.xml';
        $contents = \file_get_contents($path);
        $this->assertIsString($contents);

        return Config::loadFromXML(\dirname($path), $contents, \dirname($path), $path);
    }
}
