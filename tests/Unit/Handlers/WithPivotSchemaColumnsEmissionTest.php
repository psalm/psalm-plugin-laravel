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

        // Default joining table: string, nullable, int; an unknown column is mixed, and `created_at` is a date.
        $this->assertContains(
            '$_members: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::RELATIONS
            . "Pivot&object{role:string, note:null|string, rank:int, missing:mixed, created_at:Illuminate\Support\Carbon|null}, 'pivot'>",
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
    public function it_types_the_parents_timestamp_columns_as_dates_once_the_cast_is_provable(): void
    {
        $traces = $this->runPsalm();
        $date = 'Illuminate\Support\Carbon|null';

        // Laravel casts the parent's created/updated column names as dates once `created_at` is selected:
        // Ledger's `modified_at` is one, so it must not read as its raw schema type. A literal `updated_at`
        // that the parent does not use is a plain column.
        $this->assertContains(
            '$_entries: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Ledger, ' . self::RELATIONS
            . "Pivot&object{created_at:{$date}, modified_at:{$date}, updated_at:null|string, amount:int}, 'pivot'>",
            $traces,
        );
        // At the call site inside the relation body.
        $this->assertContains(
            '$relation: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Ledger&static, ' . self::RELATIONS
            . "Pivot&object{created_at:{$date}, modified_at:{$date}, updated_at:null|string, amount:int}, 'pivot'>",
            $traces,
        );
    }

    #[Test]
    public function it_types_with_timestamps_columns(): void
    {
        $traces = $this->runPsalm();
        $date = 'Illuminate\Support\Carbon|null';

        // In the relation body and for a caller of the registered method alike.
        $this->assertContains(
            '$relation: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team&static, ' . self::RELATIONS
            . "Pivot&object{role:string, created_at:{$date}, updated_at:{$date}}, 'pivot'>",
            $traces,
        );
        // Sheet renames its created column: a plain pivot gates its date casts on its OWN `created_at`,
        // which is not selected, so `made_at` is not proven a date.
        $this->assertContains(
            '$_plain_stamps: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Sheet, ' . self::RELATIONS
            . "Pivot&object{made_at:mixed, updated_at:mixed}, 'pivot'>",
            $traces,
        );
        // Selecting `created_at` opens the gate; it is no date of Sheet's, only `made_at` and `updated_at` are.
        $this->assertContains(
            '$_plain_stamps_created: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Sheet, ' . self::RELATIONS
            . "Pivot&object{made_at:{$date}, updated_at:{$date}, created_at:null|string}, 'pivot'>",
            $traces,
        );
        // A parent whose created column is overridden has unknown timestamp names: nothing to add for the
        // defaults, and no column type is proven.
        $this->assertContains(
            '$_odd_default: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Odd, ' . self::RELATIONS
            . "Pivot, 'pivot'>",
            $traces,
        );
        $this->assertContains(
            '$_odd_hours: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Odd, ' . self::RELATIONS
            . "Pivot&object{hours:mixed, made_at:mixed, updated_at:mixed}, 'pivot'>",
            $traces,
        );
    }

    #[Test]
    public function it_refines_a_using_pivot_from_the_relation_table(): void
    {
        $traces = $this->runPsalm();
        $date = 'Illuminate\Support\Carbon|null';

        // `@property` (label) and an explicit cast (level) win over the relation's table; the pivot class's own
        // table (`assignment_defaults`: hours is a string there) is never read; dates win over the raw schema.
        $this->assertContains(
            '$_assignments: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::MODELS
            . "Assignment&object{hours:string, label:non-empty-string, level:int, note:null|string, grade:int|null, created_at:{$date}, updated_at:{$date}}, 'pivot'>",
            $traces,
        );
        // Reads: a column the pivot class's own default table declares is read through the class, so the
        // shape carries that type (`hours` is a string there, whatever the relation's table says).
        $this->assertContains('$_hours: string', $traces);
        // A cast on a column that default table lacks takes its nullability from the relation's table.
        $this->assertContains('$_grade: int|null', $traces);
        // No `@property`, no casts: a timestamped table must still give dates, not `null|string`.
        $this->assertContains(
            '$_rota: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::MODELS
            . "Rota&object{hours:int, created_at:{$date}, updated_at:{$date}}, 'pivot'>",
            $traces,
        );
        // Order does not matter inside the relation body.
        $this->assertContains(
            '$relation: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team&static, ' . self::MODELS
            . "Rota&object{created_at:{$date}, updated_at:{$date}, hours:int}, 'pivot'>",
            $traces,
        );
        // A using() pivot gates its date casts on the parent's created column: Sheet's `made_at`.
        $this->assertContains(
            '$_rota_stamps: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Sheet, ' . self::MODELS
            . "Rota&object{made_at:{$date}, updated_at:{$date}}, 'pivot'>",
            $traces,
        );
        // A class that converts dates its own way proves no column.
        $this->assertContains(
            '$_odd_rota: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::MODELS
            . "OddRota&object{hours:mixed, created_at:mixed, updated_at:mixed}, 'pivot'>",
            $traces,
        );
        // A factory called on another model reads that model's timestamp names: nothing is proven for this one.
        $this->assertContains(
            '$_foreign: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::RELATIONS
            . "Pivot, 'pivot'>",
            $traces,
        );
        // A non-literal using() leaves the pivot class unknown: no shape.
        $this->assertContains(
            '$_dynamic_using: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::RELATIONS
            . "Pivot, 'pivot'>",
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
