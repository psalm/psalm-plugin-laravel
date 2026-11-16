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

    #[Test]
    public function it_binds_relative_native_return_types_to_the_caster(): void
    {
        $traces = $this->runPsalm();

        $this->assertContains('$_traitSelf: CasterReturnFixture\Models\TraitSelfCast', $traces);
        $this->assertContains('$_traitStatic: CasterReturnFixture\Models\TraitStaticCast', $traces);
        $this->assertContains('$_staticBase: CasterReturnFixture\Models\StaticBaseCast', $traces);
        $this->assertContains('$_parent: CasterReturnFixture\Models\StaticBaseCast', $traces);
    }

    #[Test]
    public function it_binds_inherited_trait_signatures_to_the_class_that_imported_the_trait(): void
    {
        $traces = $this->runPsalm();

        $this->assertContains('$_childTraitSelf: CasterReturnFixture\\Models\\BaseTraitSelfCast', $traces);
        $this->assertContains('$_childTraitParent: CasterReturnFixture\\Models\\LegacyBase', $traces);
    }

    #[Test]
    public function it_combines_every_caster_a_castable_may_return(): void
    {
        $this->assertContains('$_multi: int|string', $this->runPsalm());
    }

    #[Test]
    public function it_types_a_native_only_get_inherited_from_a_base_caster(): void
    {
        $this->assertContains('$_childNative: CasterReturnFixture\\Money', $this->runPsalm());
    }

    #[Test]
    public function it_reads_an_inbound_caster_as_the_raw_column_value_even_when_it_also_has_get(): void
    {
        $traces = $this->runPsalm();

        $this->assertContains('$_dual: int', $traces);
        $this->assertContains('$_dualCastable: int', $traces);
    }

    #[Test]
    public function it_keeps_a_docblock_return_type_inherited_from_a_base_caster(): void
    {
        $this->assertContains('$_inheritedDoc: CasterReturnFixture\Money', $this->runPsalm());
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
