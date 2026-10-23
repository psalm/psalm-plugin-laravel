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
        // Ledger's `modified_at` is one, so it must not read as its raw schema type. So is the default
        // `updated_at`, even though the parent renamed it: forceFill() runs before the pivot knows its parent,
        // so it date-normalizes the default names. Its integer column must not read as `int`.
        $this->assertContains(
            '$_entries: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Ledger, ' . self::RELATIONS
            . "Pivot&object{created_at:{$date}, modified_at:{$date}, updated_at:mixed, amount:int}, 'pivot'>",
            $traces,
        );
        // At the call site inside the relation body.
        $this->assertContains(
            '$relation: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Ledger&static, ' . self::RELATIONS
            . "Pivot&object{created_at:{$date}, modified_at:{$date}, updated_at:mixed, amount:int}, 'pivot'>",
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
        // Selecting `created_at` opens the gate; it is no date of Sheet's (only `made_at` and `updated_at` are), and a plain
        // pivot date-normalizes that default name before it has a parent: mixed, not Carbon nor the raw schema type.
        $this->assertContains(
            '$_plain_stamps_created: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Sheet, ' . self::RELATIONS
            . "Pivot&object{made_at:{$date}, updated_at:{$date}, created_at:mixed}, 'pivot'>",
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
    public function it_proves_no_dates_across_links_not_known_to_keep_the_relation(): void
    {
        $traces = $this->runPsalm();

        // A factory called on another model reads that model's timestamp names: nothing is proven for this one.
        $this->assertContains(
            '$_foreign: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::RELATIONS
            . "Pivot, 'pivot'>",
            $traces,
        );
        // A `where()` between the factory and `withTimestamps()` may be a replaced relation: names, no dates.
        $mixed = "Pivot&object{created_at:mixed, updated_at:mixed}, 'pivot'>";
        $this->assertContains(
            '$_filtered: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::RELATIONS . $mixed,
            $traces,
        );
        // The callback's relation joins another table, so this chain's table types nothing.
        $this->assertContains(
            '$relation: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team&static, ' . self::RELATIONS
            . "Pivot&object{role:mixed, role_at:mixed, rank:mixed}, 'pivot'>",
            $traces,
        );
        $this->assertContains(
            '$_delegated_filtered: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::RELATIONS
            . "Pivot&object{role:string, created_at:mixed, updated_at:mixed}, 'pivot'>",
            $traces,
        );
    }

    #[Test]
    public function it_trusts_only_the_named_pivot_preserving_methods(): void
    {
        $this->assertTrue(PivotColumnResolver::keepsPivot('wherepivotnotbetween'));
        $this->assertTrue(PivotColumnResolver::keepsPivot('orwherepivotnull'));
        // A macro with a familiar prefix may return anything.
        $this->assertFalse(PivotColumnResolver::keepsPivot('wherepivotrecent'));
        $this->assertFalse(PivotColumnResolver::keepsPivot('orwherepivotrecent'));
        $this->assertFalse(PivotColumnResolver::keepsPivot('using'));
    }

    #[Test]
    public function it_keeps_a_refusal_across_the_merge_of_returns(): void
    {
        $traces = $this->runPsalm();

        // One return is another model's factory: the refusal survives the merge of the returns, so the
        // delegating `withTimestamps()` adds nothing to the shape.
        $this->assertContains(
            '$_branched: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team, ' . self::RELATIONS
            . "Pivot, 'pivot'>",
            $traces,
        );
    }

    #[Test]
    public function it_types_the_dates_as_the_configured_date_class(): void
    {
        $default = 'Illuminate\\Support\\Carbon|null';
        $this->assertContains($this->stampedRelation($default), $this->runPsalm());

        // Date::use(CarbonImmutable::class): the dates are that class.
        $this->assertContains($this->stampedRelation('Carbon\\CarbonImmutable|null'), $this->runPsalm(['WITH_PIVOT_DATE' => 'immutable']));

        // A callable builds the dates: nothing is proven.
        $this->assertContains($this->stampedRelation('mixed'), $this->runPsalm(['WITH_PIVOT_DATE' => 'callable']));
    }

    private function stampedRelation(string $date): string
    {
        return '$relation: ' . self::RELATIONS . 'BelongsToMany<' . self::MODELS . 'User, ' . self::MODELS . 'Team&static, ' . self::RELATIONS
            . "Pivot&object{role:string, created_at:{$date}, updated_at:{$date}}, 'pivot'>";
    }

    /**
     * @param array<string, string> $env
     * @return list<string> the `@psalm-trace` messages
     */
    private function runPsalm(array $env = []): array
    {
        $fixtureDir = __DIR__ . '/Fixtures/WithPivotSchemaColumns';
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--show-info=true', '--output-format=json'],
            $fixtureDir,
            $env,
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
