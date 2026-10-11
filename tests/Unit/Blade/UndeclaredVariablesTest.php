<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ComponentViewSeedHandler;
use Psalm\LaravelPlugin\Blade\UndeclaredVariableRegistry;

/**
 * #1827: `<blade reportUndeclaredVariables="true" />` leaves a contract template's undeclared reads
 * to Psalm's own UndefinedGlobalVariable / UndefinedVariable, unless something the run can see
 * still provides the name.
 */
#[CoversClass(UndeclaredVariableRegistry::class)]
#[CoversClass(ComponentViewSeedHandler::class)]
#[Group('subprocess')]
final class UndeclaredVariablesTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/UndeclaredVariables';

    /** @var list<string> */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    /** @var list<string> */
    private const EXPECTED = [
        'components/profile.blade.php:3 UndefinedGlobalVariable $profileTypo',
        'typo.blade.php:3 UndefinedGlobalVariable $titel',
        'typo.blade.php:4 UndefinedVariable $insideArrow',
    ];

    /**
     * Everything else in the fixture is a decline or an allowance: a template without a contract,
     * a `View::share()` key and `$app`, a composer's and a creator's view, `@props`, a Livewire
     * attribute and a `livewire.*` view, a seeded class component's own data keys (untyped property,
     * `render()` key, named slot, `$componentName`), a class component view also `@include`d, and an
     * anonymous component view.
     */
    #[Test]
    public function reports_only_provably_undeclared_reads(): void
    {
        $this->assertSame(self::EXPECTED, $this->undeclaredIn($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS)));
    }

    #[Test]
    public function the_flag_off_reports_nothing(): void
    {
        $arguments = ['-c', 'psalm-off.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

        $this->assertSame([], $this->undeclaredIn($this->fixtureIssues(self::FIXTURE, $arguments)));
    }

    /** A warm run skips the compiler; the omitted names come back from the manifest. */
    #[Test]
    public function a_warm_shadow_cache_reports_the_same(): void
    {
        $run = $this->analyzeFixtureSequence(self::FIXTURE, [self::ARGUMENTS, self::ARGUMENTS]);

        $this->assertSame(self::EXPECTED, $this->undeclaredIn($this->decodeIssues($run)));
    }

    /**
     * @param list<array<string, mixed>> $issues
     *
     * @return list<string>
     */
    private function undeclaredIn(array $issues): array
    {
        $this->assertNotSame([], $issues, 'the fixture run reported nothing at all.');

        $found = [];

        foreach ($issues as $issue) {
            $type = (string) $issue['type'];

            if (\preg_match('/^(Possibly)?Undefined(Global)?Variable$/', $type) !== 1) {
                continue;
            }

            \preg_match('/\$\w+/', (string) $issue['message'], $name);
            $file = \str_replace('resources/views/', '', (string) $issue['file_name']);
            $found[] = $file . ':' . $issue['line_from'] . ' ' . $type . ' ' . ($name[0] ?? '?');
        }

        \sort($found);

        return $found;
    }
}
