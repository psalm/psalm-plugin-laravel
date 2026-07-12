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
    public function explicit_scoped_filters_are_not_replaced(bool $enforced, string $_expectedDefaultLevel): void
    {
        $config = $this->loadConfigFile('filter-only.xml');

        ExperimentalIssuePolicy::apply($enforced);

        $this->assertSame(Config::REPORT_SUPPRESS, $config->getReportingLevelForFile('UndefinedModelRelation', __FILE__));
        $this->assertSame($_expectedDefaultLevel, $config->getReportingLevelForFile('UndefinedModelRelation', \dirname(__DIR__, 3) . '/src/Elsewhere.php'));
    }

    #[Test]
    #[DataProvider('enforcementModes')]
    public function explicit_global_levels_remain_in_effect_alongside_scoped_filters(bool $enforced, string $_expectedDefaultLevel): void
    {
        $config = $this->loadConfigFile('explicit-error-and-filter.xml');

        ExperimentalIssuePolicy::apply($enforced);

        $this->assertSame(Config::REPORT_SUPPRESS, $config->getReportingLevelForFile('UndefinedModelRelation', __FILE__));
        $this->assertSame(Config::REPORT_ERROR, $config->getReportingLevelForFile('UndefinedModelRelation', \dirname(__DIR__, 3) . '/src/Elsewhere.php'));
    }

    #[Test]
    public function a_later_invocation_uses_its_own_configured_mode(): void
    {
        $first = $this->loadConfig();
        ExperimentalIssuePolicy::apply(true);
        $this->assertSame(Config::REPORT_ERROR, $first->getReportingLevelForFile('UnknownModelAttribute', __FILE__));

        $second = $this->loadConfig();
        ExperimentalIssuePolicy::apply(false);
        $this->assertSame(Config::REPORT_INFO, $second->getReportingLevelForFile('UnknownModelAttribute', __FILE__));
    }

    private function loadConfig(string $body = ''): Config
    {
        return Config::loadFromXML(
            \dirname(__DIR__, 3),
            '<?xml version="1.0"?><psalm xmlns="https://getpsalm.org/schema/config">' . $body . '</psalm>',
        );
    }

    private function loadConfigFile(string $name): Config
    {
        $path = __DIR__ . '/Fixtures/ExperimentalIssuePolicy/' . $name;
        $contents = \file_get_contents($path);
        $this->assertIsString($contents);

        return Config::loadFromXML(\dirname($path), $contents, \dirname($path), $path);
    }
}
