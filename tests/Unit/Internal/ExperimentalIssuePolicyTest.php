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
    public function explicit_issue_policy_owns_the_base_level_and_scoped_filters(bool $enforced, string $_expectedDefaultLevel): void
    {
        $config = $this->loadConfig(
            '<issueHandlers><PluginIssue name="UndefinedModelRelation" errorLevel="info">'
            . '<errorLevel type="suppress"><directory name="tests/Unit/Internal" /></errorLevel>'
            . '</PluginIssue></issueHandlers>',
        );

        ExperimentalIssuePolicy::apply($enforced);

        $this->assertSame(Config::REPORT_SUPPRESS, $config->getReportingLevelForFile('UndefinedModelRelation', __FILE__));
        $this->assertSame(Config::REPORT_INFO, $config->getReportingLevelForFile('UndefinedModelRelation', \dirname(__DIR__, 3) . '/src/Elsewhere.php'));
    }

    /** @return iterable<string, array{bool, bool, 'error'|'info'}> */
    public static function enforcementFlips(): iterable
    {
        yield 'advisory to enforced' => [false, true, Config::REPORT_ERROR];
        yield 'enforced to advisory' => [true, false, Config::REPORT_INFO];
    }

    #[Test]
    #[DataProvider('enforcementFlips')]
    public function plugin_defaults_follow_sequential_invocations_on_the_same_config(
        bool $initial,
        bool $subsequent,
        string $expectedLevel,
    ): void {
        $config = $this->loadConfig();

        ExperimentalIssuePolicy::apply($initial);
        ExperimentalIssuePolicy::apply($subsequent);

        foreach (['UnknownModelAttribute', 'UndefinedModelRelation'] as $issueType) {
            $this->assertSame($expectedLevel, $config->getReportingLevelForFile($issueType, __FILE__));
        }
    }

    #[Test]
    public function an_explicit_handler_stays_unchanged_across_sequential_invocations(): void
    {
        $config = $this->loadConfig(
            '<issueHandlers><PluginIssue name="UndefinedModelRelation" errorLevel="info">'
            . '<errorLevel type="suppress"><directory name="tests/Unit/Internal" /></errorLevel>'
            . '</PluginIssue></issueHandlers>',
        );

        ExperimentalIssuePolicy::apply(false);
        ExperimentalIssuePolicy::apply(true);

        $this->assertSame(Config::REPORT_SUPPRESS, $config->getReportingLevelForFile('UndefinedModelRelation', __FILE__));
        $this->assertSame(Config::REPORT_INFO, $config->getReportingLevelForFile('UndefinedModelRelation', \dirname(__DIR__, 3) . '/src/Elsewhere.php'));
    }

    private function loadConfig(string $body = ''): Config
    {
        return Config::loadFromXML(
            \dirname(__DIR__, 3),
            '<?xml version="1.0"?><psalm xmlns="https://getpsalm.org/schema/config">' . $body . '</psalm>',
        );
    }
}
