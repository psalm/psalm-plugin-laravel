<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for custom casters whose `get()` declares only a native return type (#1662).
 *
 * Psalm answers such a method with the interface's unresolved `TGet|null`, so the property read must fall back to
 * the caster's own signature. The psalm-tester harness boots Testbench with no migrations, so only a real Psalm
 * subprocess against a fixture project with migrations sees a cast column.
 */
#[CoversClass(CastResolver::class)]
#[Group('subprocess')]
final class CasterReturnTypesEmissionTest extends TestCase
{
    /** @var list<string>|null `@psalm-trace` messages */
    private static ?array $traces = null;

    #[Test]
    public function it_types_a_native_only_get_by_its_signature_with_and_without_implements(): void
    {
        $traces = $this->runPsalm();

        $this->assertContains('$_generic: CasterReturnFixture\Money', $traces);
        $this->assertContains('$_native: CasterReturnFixture\Money', $traces);
    }

    #[Test]
    public function it_keeps_the_docblock_return_type_of_get(): void
    {
        $this->assertContains('$_documented: CasterReturnFixture\Money', $this->runPsalm());
    }

    /** @return list<string> messages of every `@psalm-trace` */
    private function runPsalm(): array
    {
        if (self::$traces !== null) {
            return self::$traces;
        }

        $fixtureDir = __DIR__ . '/Fixtures/CasterReturnTypes';
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--show-info=true', '--output-format=json'],
            $fixtureDir,
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);

        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$process->getOutput()}\nstderr:\n{$process->getErrorOutput()}");

        $traces = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && ($finding['type'] ?? null) === 'Trace' && \is_string($finding['message'] ?? null)) {
                $traces[] = $finding['message'];
            }
        }

        return self::$traces = $traces;
    }
}
