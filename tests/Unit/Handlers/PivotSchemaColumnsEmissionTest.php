<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\BelongsToManyPivotColumnsHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotColumnTypes;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the schema-typed pivot shape that `withPivot()` / `withTimestamps()` add to a
 * many-to-many relation (#1944).
 *
 * The psalm-tester harness boots Testbench with no migrations, so a phpt can only see `mixed` pivot
 * columns. This points a real Psalm subprocess at a fixture project whose pivot tables have a migration
 * and asserts the `@psalm-trace` messages: `role:string`, `level:int|null`, date-cast timestamps as
 * `Carbon|null`, for both in-body chains and call sites typed through the model method. Further fixtures
 * cover renamed or missing parent timestamp columns, `using()` pivots with casts, and a relation inherited
 * by a subclass (default joining table follows the receiver).
 */
#[CoversClass(BelongsToManyPivotColumnsHandler::class)]
#[CoversClass(PivotColumnTypes::class)]
#[Group('subprocess')]
final class PivotSchemaColumnsEmissionTest extends TestCase
{
    private const ISSUES_OF_INTEREST = [
        'InvalidReturnType',
        'InvalidReturnStatement',
        'InvalidArgument',
        'TooManyArguments',
        'TooManyTemplateParams',
        'UndefinedMethod',
        'UndefinedMagicMethod',
    ];

    private const CARBON = 'Illuminate\Support\Carbon';

    /** @var array{issues: array<string, list<string>>, traces: array<string, array<string, string>>}|null */
    private static ?array $findings = null;

    #[Test]
    public function it_reports_no_return_type_issues_for_refined_pivot_chains(): void
    {
        // #1944 point 1: a refined inferred pivot must satisfy every declared form (none, 2-param, plain
        // 4-param, MorphPivot, custom pivot, declared object shape) on the relation methods.
        $this->assertSame([], $this->runPsalm()['issues'], 'The fixture must report no return-type or resolution issues.');
    }

    #[Test]
    public function it_types_in_body_chains_from_the_migration_schema(): void
    {
        $team = 'app/Models/Team.php';
        $carbon = self::CARBON;

        // Default joining table `team_user` (belongsToMany($related) without a table argument).
        $this->assertTraceContains(
            $team,
            'inBodyDefault',
            "Pivot&object{role:string, level:int|null, created_at:{$carbon}|null, updated_at:{$carbon}|null}, 'pivot'>",
        );
        // Explicit literal table.
        $this->assertTraceContains($team, 'inBodyExplicit', "Pivot&object{role:string}, 'pivot'>");
        // Custom table name plus a boolean column.
        $this->assertTraceContains($team, 'inBodyCustom', "Pivot&object{title:string, is_owner:bool}, 'pivot'>");
        // morphToMany default table `taggables`, MorphPivot slot.
        $this->assertTraceContains($team, 'inBodyMorph', "MorphPivot&object{note:string}, 'pivot'>");
        // using() narrows the pivot class, the shape is added on top of it.
        $this->assertTraceContains($team, 'inBodyUsing', "PivotSchemaFixture\\Models\\TeamUserPivot&object{role:string}, 'pivot'>");
    }

    #[Test]
    public function it_types_call_sites_through_the_model_method(): void
    {
        $positive = 'app/Positive.php';
        $carbon = self::CARBON;

        // Undocumented relation method: shape parsed from the body, table from the default joining-table rule.
        $this->assertTraceContains(
            $positive,
            'siteMembers',
            "Pivot&object{role:string, level:int|null, created_at:{$carbon}|null, updated_at:{$carbon}|null}, 'pivot'>",
        );
        $this->assertTraceContains($positive, 'siteExplicit', "Pivot&object{role:string}, 'pivot'>");
        $this->assertTraceContains($positive, 'siteMemberships', "Pivot&object{title:string, is_owner:bool}, 'pivot'>");
        $this->assertTraceContains($positive, 'siteTags', "MorphPivot&object{note:string}, 'pivot'>");
        $this->assertTraceContains($positive, 'siteUsing', "PivotSchemaFixture\\Models\\TeamUserPivot&object{role:string}, 'pivot'>");
    }

    #[Test]
    public function it_keeps_a_declared_pivot_shape_at_call_sites(): void
    {
        $trace = $this->trace('app/Positive.php', 'siteShaped');

        // The method body also selects `level`, but the declared shape carries `role` only and must win.
        $this->assertStringContainsString("Pivot&object{role:string}, 'pivot'>", $trace);
        $this->assertStringNotContainsString('level', $trace);
    }

    #[Test]
    public function it_gates_the_date_casts_on_the_pivot_class_when_the_parent_renames_its_timestamps(): void
    {
        $carbon = self::CARBON;
        $model = 'app/Models/StampedTeam.php';
        $positive = 'app/Positive.php';
        $plain = "Pivot&object{made_at:null|string, changed_at:null|string}, 'pivot'>";
        $using = "PivotSchemaFixture\\Models\\StampedPivot&object{made_at:{$carbon}|null, changed_at:{$carbon}|null}, 'pivot'>";

        // A plain Pivot is built before its parent is attached, so its date-cast gate is the literal
        // `created_at`: the renamed columns stay the migration's (nullable) timestamps, not Carbon.
        $this->assertTraceContains($positive, 'siteStamped', $plain);
        $this->assertStringNotContainsString($carbon, $this->trace($positive, 'siteStamped'));
        $this->assertTraceContains($model, 'inBodyStamped', $plain);

        // A using() class is built after the parent is attached, so the parent's `made_at` opens the gate.
        $this->assertTraceContains($positive, 'siteStampedUsing', $using);
        $this->assertTraceContains($model, 'inBodyStampedUsing', $using);
    }

    #[Test]
    public function it_casts_only_the_parent_timestamp_columns_that_exist(): void
    {
        $carbon = self::CARBON;

        // Parent `CREATED_AT = null`: the plain pivot's literal `created_at` still opens the gate, but only the
        // parent's `updated_at` is cast, so `created_at` keeps the migration type.
        $this->assertTraceContains(
            'app/Positive.php',
            'siteNoCreatedAt',
            "Pivot&object{role:string, created_at:null|string, updated_at:{$carbon}|null}, 'pivot'>",
        );
    }

    #[Test]
    public function it_types_custom_pivot_columns_through_the_pivot_class_casts(): void
    {
        $carbon = self::CARBON;
        $status = 'PivotSchemaFixture\\Models\\MembershipStatus';

        $this->assertTraceContains(
            'app/Positive.php',
            'siteCasted',
            "PivotSchemaFixture\\Models\\CastedPivot&object{status:{$status}, is_active:bool, approved_at:{$carbon}, meta:array<array-key, mixed>|null}, 'pivot'>",
        );
    }

    #[Test]
    public function it_resolves_the_default_joining_table_on_the_receiver_of_an_inherited_relation(): void
    {
        $carbon = self::CARBON;

        // Team::members() has no table argument; on a SpecialTeam receiver the default table is
        // `special_team_user` (role:int, level:string|null), not the declaring class's `team_user`.
        $trace = $this->trace('app/Positive.php', 'siteSpecial');

        $this->assertStringContainsString(
            "Pivot&object{role:int, level:null|string, created_at:{$carbon}|null, updated_at:{$carbon}|null}, 'pivot'>",
            $trace,
        );
        $this->assertStringContainsString('SpecialTeam', $trace);
    }

    private function assertTraceContains(string $file, string $variable, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->trace($file, $variable));
    }

    private function trace(string $file, string $variable): string
    {
        $traces = $this->runPsalm()['traces'][$file] ?? [];

        $this->assertArrayHasKey($variable, $traces, "No @psalm-trace for \${$variable} in {$file}; got: " . \implode(', ', \array_keys($traces)));

        return $traces[$variable];
    }

    /**
     * @return array{issues: array<string, list<string>>, traces: array<string, array<string, string>>}
     *     issues: "Type: message" per project-relative file; traces: trace message per variable name per file
     */
    private function runPsalm(): array
    {
        if (self::$findings !== null) {
            return self::$findings;
        }

        $fixtureDir = __DIR__ . '/Fixtures/PivotSchemaColumns';
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

        $issues = [];
        $traces = [];
        foreach ($decoded as $finding) {
            if (!\is_array($finding) || !isset($finding['type'], $finding['message'], $finding['file_name'])) {
                continue;
            }

            $file = (string) $finding['file_name'];

            if ($finding['type'] === 'Trace') {
                // Trace messages read "$variable: <type>".
                if (\preg_match('/^\$(\w+): /', (string) $finding['message'], $matches) === 1) {
                    $traces[$file][$matches[1]] = (string) $finding['message'];
                }

                continue;
            }

            if (\in_array($finding['type'], self::ISSUES_OF_INTEREST, true)) {
                $issues[$file][] = $finding['type'] . ': ' . $finding['message'];
            }
        }

        return self::$findings = ['issues' => $issues, 'traces' => $traces];
    }
}
