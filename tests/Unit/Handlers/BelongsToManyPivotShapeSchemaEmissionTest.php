<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\PivotShapeResolver;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the schema-typed pivot columns of `withPivot()` / `withTimestamps()` (#1944).
 *
 * The psalm-tester harness boots Testbench with no migrations, so a phpt only sees `mixed` columns. This
 * points a real Psalm subprocess at a fixture project whose registered models have migrations, and
 * asserts the call-site relation types through the `psalm-trace` lines of `app/Positive.php`.
 */
#[CoversClass(PivotShapeResolver::class)]
#[Group('subprocess')]
final class BelongsToManyPivotShapeSchemaEmissionTest extends TestCase
{
    /**
     * `app/Positive.php`, in source order: one `psalm-trace` per relation under test. `{R}` stands for the
     * `Illuminate\Database\Eloquent\Relations\` namespace, `{M}` for the fixture's models, `{C}` for Carbon.
     * `psalm-check-type-exact` would not do: it compares object shapes by mutual containment, so it cannot
     * tell `level: int` from `level: string`.
     */
    private const EXPECTED_TRACES = [
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{role:string, created_at:{C}|null, updated_at:{C}|null}, \'pivot\'>',
        'Trace: $pivot: {R}Pivot&object{role:string, created_at:{C}|null, updated_at:{C}|null}',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{level:int<0, max>, notes:null|string, kind:\'lead\'|\'member\', unknown_column:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{extra:float, level:int<0, max>}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{role:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{seat:string}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{level:int<0, max>, notes:null|string, kind:\'lead\'|\'member\', unknown_column:mixed, score:int}, \'pivot\'>',
        'Trace: $stock: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{created_at:{C}|null, updated_at:{C}|null}, \'pivot\'>',
        'Trace: $renamed: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{added_at:null|string, updated_at:null|string}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {M}RoleAssignment&object{level:string, role:string}, \'pivot\'>',
        'Trace: $relation: {R}MorphToMany<{M}Tag, {M}Team, {R}MorphPivot&object{weight:int, created_at:{C}|null, updated_at:{C}|null}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}Perch, {M}Team, {R}Pivot&object{seat:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Squad, {R}Pivot&object{seat:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{level:int<0, max>}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {M}RoleAssignment, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {M}PartialPivot&object{role:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {M}RoleAssignment&object{created_at:{C}|null, updated_at:{C}|null}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{nested:mixed, label:string}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Rack, {R}Pivot&object{role:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}Item, {M}Order, {R}Pivot&object{quantity:int, unit_price:float}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{updated_at:mixed, level:int<0, max>}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{updated_at:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {M}ManualDatesPivot&object{created_at:mixed, updated_at:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {M}NoTimestampsPivot&object{created_at:mixed, updated_at:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {R}Pivot&object{notes:null|string}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Team, {M}AddedAtPivot&object{added_at:mixed, level:mixed}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Dock, {R}Pivot&object{added_at:{C}|null, updated_at:{C}|null}, \'pivot\'>',
        'Trace: $relation: {R}BelongsToMany<{M}User, {M}Dock, {R}Pivot&object{added_at:null|string, updated_at:null|string}, \'pivot\'>',
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $findings = null;

    #[Test]
    public function it_types_pivot_columns_from_the_schema_at_the_call_site(): void
    {
        $findings = $this->runPsalm();

        $expected = \array_map(
            static fn(string $trace): string => \strtr($trace, [
                '{R}' => 'Illuminate\\Database\\Eloquent\\Relations\\',
                '{M}' => 'PivotShapeFixture\\Models\\',
                '{C}' => 'Illuminate\\Support\\Carbon',
            ]),
            self::EXPECTED_TRACES,
        );

        $r = 'Illuminate\\Database\\Eloquent\\Relations\\';
        $m = 'PivotShapeFixture\\Models\\';
        $c = 'Illuminate\\Support\\Carbon';

        $this->assertSame($expected, $findings['app/Positive.php'] ?? [], 'Positive.php must report exactly the expected relation types and nothing else.');
        // In-body traces: the relation held in a variable hides an explicit using(Pivot::class), so the date columns
        // the two hydration rules disagree on are mixed (Dock), and the ones they agree on keep Carbon (Team).
        $this->assertSame([
            'Trace: $result: ' . $r . 'BelongsToMany<' . $m . 'User, ' . $m . 'Dock, ' . $r . "Pivot&object{created_at:mixed, updated_at:mixed}, 'pivot'>&static",
        ], $findings['app/Models/Dock.php'] ?? []);
        $this->assertSame([
            'Trace: $result: ' . $r . 'BelongsToMany<' . $m . 'User, ' . $m . 'Team, ' . $r . 'Pivot&object{created_at:' . $c . '|null, updated_at:' . $c . "|null}, 'pivot'>&static",
        ], $findings['app/Models/Team.php'] ?? []);
        $this->assertSame([], $findings['app/Models/Squad.php'] ?? []);
    }

    #[Test]
    public function it_reports_a_wrong_check_type_so_the_positive_run_is_not_vacuous(): void
    {
        $messages = $this->runPsalm()['app/Sentinel.php'] ?? [];

        $this->assertCount(1, $messages, \implode("\n", $messages));
        $this->assertStringStartsWith('CheckType: ', $messages[0]);
    }

    /**
     * @return array<string, list<string>> "Type: message" per project-relative file
     */
    private function runPsalm(): array
    {
        if (self::$findings !== null) {
            return self::$findings;
        }

        $fixtureDir = __DIR__ . '/Fixtures/PivotShapeSchema';
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

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && isset($finding['type'], $finding['message'], $finding['file_name'])) {
                $findings[(string) $finding['file_name']][] = $finding['type'] . ': ' . $finding['message'];
            }
        }

        return self::$findings = $findings;
    }
}
