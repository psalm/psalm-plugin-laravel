<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotColumnResolver;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for `withPivot()` column types read from the pivot table's migration (#1944).
 *
 * The psalm-tester harness boots Testbench with no migrations, so a phpt only ever sees `mixed`
 * columns. This points a real Psalm subprocess at a fixture project with pivot-table migrations.
 */
#[CoversClass(PivotColumnResolver::class)]
#[Group('subprocess')]
final class WithPivotSchemaColumnsEmissionTest extends TestCase
{
    private const RELATIONS = 'Illuminate\Database\Eloquent\Relations\\';

    private const MODELS = 'WithPivotFixture\Models\\';

    #[Test]
    public function it_types_pivot_columns_from_the_pivot_table_migration(): void
    {
        $traces = $this->runPsalm();

        // Default joining table: string, nullable, int; an unknown column and a timestamp stay mixed.
        $this->assertContains(
            '$_members: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::RELATIONS
            . "Pivot&object{role:string, note:null|string, rank:int, missing:mixed, created_at:mixed}, 'pivot'>",
            $traces,
        );
        // Explicit table, at the call site inside the relation body.
        $this->assertContains(
            '$relation: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team&static, ' . self::RELATIONS
            . "Pivot&object{admin:bool}, 'pivot'>",
            $traces,
        );
        // morphToMany: the pluralized morph name.
        $this->assertContains(
            '$_tags: ' . self::RELATIONS . 'MorphToMany<' . self::MODELS . 'Tag, ' . self::MODELS . 'Team, ' . self::RELATIONS
            . "MorphPivot&object{weight:float}, 'pivot'>",
            $traces,
        );
        // An overridden joiningTableSegment() leaves the table unknown.
        $this->assertContains(
            '$_squad: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Squad, ' . self::RELATIONS
            . "Pivot&object{role:mixed}, 'pivot'>",
            $traces,
        );
        // An overridden joiningTable() leaves the table unknown too.
        $this->assertContains(
            '$_crew: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Crew, ' . self::RELATIONS
            . "Pivot&object{admin:mixed}, 'pivot'>",
            $traces,
        );
        // Delegation: the outer columns are typed from the delegated relation's table.
        $this->assertContains(
            '$_admins: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::RELATIONS
            . "Pivot&object{admin:bool}, 'pivot'>",
            $traces,
        );
    }

    #[Test]
    public function it_keeps_the_parents_timestamp_columns_mixed(): void
    {
        $traces = $this->runPsalm();

        // Laravel casts the parent's created/updated column names as dates once `created_at` is selected:
        // Ledger's `modified_at` is one, so it must not read as its raw schema type. A literal `updated_at`
        // that the parent does not use is a plain column.
        $this->assertContains(
            '$_entries: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Ledger, ' . self::RELATIONS
            . "Pivot&object{created_at:mixed, modified_at:mixed, updated_at:null|string, amount:int}, 'pivot'>",
            $traces,
        );
        // At the call site inside the relation body.
        $this->assertContains(
            '$relation: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Ledger&static, ' . self::RELATIONS
            . "Pivot&object{created_at:mixed, modified_at:mixed, updated_at:null|string, amount:int}, 'pivot'>",
            $traces,
        );
    }

    /**
     * @return list<string> the `@psalm-trace` messages
     */
    private function runPsalm(): array
    {
        $fixtureDir = __DIR__ . '/Fixtures/WithPivotSchemaColumns';
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

        return $traces;
    }
}
