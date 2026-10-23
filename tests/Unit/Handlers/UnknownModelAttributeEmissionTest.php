<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\UnknownModelAttributeHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the actual emission of {@see UnknownModelAttributeHandler}. The pure verdict
 * is unit-tested in {@see UnknownModelAttributeHandlerTest}, and the `UnknownModelAttributeTest.phpt`
 * type test only guards the schema-empty defer — the psalm-tester harness boots Testbench with no
 * migrations, so no app model there ever has a populated schema and the rule never gets to fire. This
 * points a real Psalm subprocess at a self-contained fixture project with a real migration, so the
 * registry resolves an actual column schema and the rule fires for real — including through the
 * `static::create()` / `self::create()` receiver forms used idiomatically inside a model's own
 * methods, which a name-resolution bug once let bypass the rule silently (no FQCN rewrite for
 * `self`/`static`, unlike a plain class reference).
 *
 * Like {@see UnresolvableAppendedModelAttributeEmissionTest}, this forks a real `vendor/bin/psalm` (it
 * boots Laravel via the plugin, ~6s) because the emission cannot be observed in-process. It lives in
 * tests/Unit for proximity to the handler it guards.
 */
#[CoversClass(UnknownModelAttributeHandler::class)]
#[Group('subprocess')]
final class UnknownModelAttributeEmissionTest extends TestCase
{
    /**
     * Flagged key => ["Receiver::method" the message must start with, short name of the model it must
     * name]; keys are unique across the fixture.
     *
     * @var array<string, array{string, string}>
     */
    private const EXPECTED_CREATION_PATH_FINDINGS = [
        'typo_query' => ['Builder::create', 'KnownThing'],
        'typo_postquery' => ['PostBuilder::create', 'Post'],
        'typo_postbuilder' => ['PostBuilder::forceCreate', 'Post'],
        'typo_genericbuilder' => ['GenericBuilder::make', 'Post'],
        'typo_hasmany' => ['HasMany::create', 'Post'],
        'typo_forcequiet' => ['HasMany::forceCreateQuietly', 'Post'],
        'typo_chained' => ['HasMany::create', 'Post'],
        'typo_named' => ['HasMany::create', 'Post'],
        'typo_namedrecords' => ['HasMany::createMany', 'Post'],
        'typo_make' => ['HasMany::make', 'Post'],
        'typo_makemany' => ['HasMany::makeMany', 'Post'],
        'typo_many' => ['HasMany::createMany', 'Post'],
        'typo_tag' => ['BelongsToMany::create', 'Tag'],
        'typo_tagmany' => ['BelongsToMany::createMany', 'Tag'],
        'typo_morphmany' => ['MorphMany::create', 'Post'],
        'typo_morphtomany' => ['MorphToMany::create', 'Tag'],
        'typo_nullsafe' => ['Builder::create', 'Post'],
        'typo_reordered' => ['BelongsToMany::create', 'Tag'],
        'typo_reordered_many' => ['BelongsToMany::createMany', 'Tag'],
        'typo_stock_control' => ['Tag::create', 'Tag'],
        'typo_this_relation' => ['HasMany::create', 'Post'],
        'typo_this_builder' => ['PostBuilder::create', 'Post'],
    ];

    #[Test]
    public function it_reports_typos_through_every_receiver_form_and_stays_silent_on_clean_calls(): void
    {
        $findings = $this->runPsalmAndCollectFindings();

        $messages = \array_map(
            static fn(array $finding): string => $finding['message'],
            $findings,
        );
        $joined = \implode("\n", $messages);

        // One finding per typo'd key, through each of the three Model receiver forms exercised by the
        // fixture: static::, self::, and a plain external class reference. The "clean" call sites
        // (makeClean, create_clean_from_outside) and the dynamically declared migration column must
        // stay silent — asserting an exact count proves both that the rule fires and that it does not
        // over-fire when a registered table has no statically parsed columns.
        $this->assertStringContainsString("'nmae'", $joined, 'static::create() with a typo must be flagged.');
        $this->assertStringContainsString("'unknown_col'", $joined, 'self::create() with a typo must be flagged.');
        $this->assertStringContainsString("'bad_key'", $joined, 'A plain external Model::create() with a typo must be flagged.');
        $this->assertStringNotContainsString("'real_col'", $joined, 'A dynamically declared migration column must not be flagged.');

        // #1679: creation calls on builder and relation receivers (CreationPathsUsage.php). Each
        // `typo_*` key names the receiver::method() and the model the message must carry.
        foreach (self::EXPECTED_CREATION_PATH_FINDINGS as $key => [$prefix, $model]) {
            $this->assertMatchesRegularExpression(
                '/^' . \preg_quote($prefix, '/') . '\(\) is assigned an unknown attribute \'' . \preg_quote($key, '/') . '\'\..* of ' . $model . ' — check for a typo\.$/m',
                $joined,
                "'{$key}' must be flagged as {$prefix}() against {$model}.",
            );
        }

        // Receivers/methods the rule must decline: update() bypasses fill(), pivot (second-arg) keys,
        // unresolved templates, ambiguous receivers, unlisted methods — all carry a `silent_` prefix.
        $this->assertStringNotContainsString("'custom_fields'", $joined, 'A model with a trait-widened guard must not be flagged.');
        $this->assertStringNotContainsString("'silent_", $joined, 'A declined receiver/method must not be flagged.');

        $this->assertCount(
            3 + \count(self::EXPECTED_CREATION_PATH_FINDINGS),
            $findings,
            "Unexpected UnknownModelAttribute finding count, got:\n{$joined}",
        );
        $this->assertSame(\array_fill(0, \count($findings), 'info'), \array_column($findings, 'severity'));
    }

    #[Test]
    public function experimental_enforcement_promotes_the_same_findings_to_errors(): void
    {
        $findings = $this->runPsalmAndCollectFindings('psalm-experimental.xml');

        $this->assertCount(3 + \count(self::EXPECTED_CREATION_PATH_FINDINGS), $findings);
        $this->assertSame(\array_fill(0, \count($findings), 'error'), \array_column($findings, 'severity'));
    }

    /**
     * @return list<array{type: string, message: string, severity: string}>
     */
    private function runPsalmAndCollectFindings(string $config = 'psalm.xml'): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/UnknownModelAttribute';
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', $config, '--no-cache', '--threads=1', '--no-progress', '--show-info=true', '--output-format=json'],
            $fixtureDir,
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $stdout = $process->getOutput();
        $decoded = \json_decode($stdout, true);

        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$stdout}\nstderr:\n{$process->getErrorOutput()}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (!\is_array($finding) || !isset($finding['type'], $finding['message'], $finding['severity'])) {
                continue;
            }

            if ($finding['type'] === 'UnknownModelAttribute') {
                $findings[] = [
                    'type' => $finding['type'],
                    'message' => (string) $finding['message'],
                    'severity' => (string) $finding['severity'],
                ];
            }
        }

        return $findings;
    }
}
