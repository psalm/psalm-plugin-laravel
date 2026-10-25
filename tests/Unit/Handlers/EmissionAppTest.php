<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Diagnostics\SuppressHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelAggregateLoadHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\DynamicWhereResolver;
use Psalm\LaravelPlugin\Handlers\Filesystem\StorageHandler;
use Psalm\LaravelPlugin\Handlers\Rules\UndefinedModelRelationHandler;
use Psalm\LaravelPlugin\Handlers\Rules\UnknownModelAttributeHandler;
use Psalm\LaravelPlugin\Handlers\Rules\UnregisteredRouteNameHandler;
use Psalm\LaravelPlugin\Handlers\Rules\UnresolvableAppendedModelAttributeHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for handler behavior that cannot be observed from a `.phpt` or in-process, run as
 * ONE real `vendor/bin/psalm` over ONE fixture app (Fixtures/EmissionApp) that every test below reads
 * from. Each scenario lives in its own `app/<Scenario>/` directory, and a test asserts only on findings
 * from its own directory, so scenarios cannot interfere.
 *
 * Why not phpts: the psalm-tester harness boots the Testbench fallback (no migrations, routes, or
 * `config/filesystems.php` of the analysed project), passes the snippet as a file argument (Psalm only
 * runs whole-program dead-code detection over `<projectFiles>`), and never registers models for the
 * metadata warm-up (phpt-declared classes are not autoloadable, and `is_a(..., true)` must be able to
 * fire a class file to reproduce the autoload crashes). The fixture therefore has a real
 * `bootstrap/app.php`, migrations, a disk config, a route file, and a private-namespace autoloader.
 *
 * Boots Laravel once (~6s) instead of once per scenario; the run uses a private cache directory because
 * the plugin caches the migration schema outside `--no-cache`, which would skip the init-time build.
 */
#[CoversClass(UnknownModelAttributeHandler::class)]
#[CoversClass(UndefinedModelRelationHandler::class)]
#[CoversClass(ModelAggregateLoadHandler::class)]
#[CoversClass(CastResolver::class)]
#[CoversClass(ModelMetadataRegistryBuilder::class)]
#[CoversClass(SchemaAggregator::class)]
#[CoversClass(SuppressHandler::class)]
#[CoversClass(DynamicWhereResolver::class)]
#[CoversClass(UnresolvableAppendedModelAttributeHandler::class)]
#[CoversClass(StorageHandler::class)]
#[CoversClass(UnregisteredRouteNameHandler::class)]
#[Group('subprocess')]
final class EmissionAppTest extends TestCase
{
    /**
     * UnknownModelAttribute: flagged key => ["Receiver::method" the message must start with, short name
     * of the model it must name]; keys are unique across the scenario.
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

    /**
     * DynamicWhereSchemaColumns: issue types a failed dynamic `where{Column}` resolution surfaces as.
     */
    private const DYNAMIC_WHERE_ISSUES = [
        'UndefinedMagicMethod',
        'MixedMethodCall',
        'MixedReturnStatement',
        'MixedAssignment',
        'InvalidScalarArgument',
        'InvalidArgument',
        'InvalidNamedArgument',
        'ArgumentTypeCoercion',
        'InaccessibleMethod',
        'InvalidReturnType',
        'InvalidReturnStatement',
        'TooManyArguments',
    ];

    /**
     * SuppressScopeUnusedCode: methods Eloquent dispatches indirectly, so each MUST be suppressed
     * (absent from the report). Covers the trait-hosted forms (the regression) and the direct forms
     * (no-regression controls). `secret` is deliberately NOT here: a private #[Scope] is structurally
     * unflaggable on a Model, so asserting its absence would prove nothing.
     */
    private const SUPPRESSED_MARKERS = [
        '::scopeFlagged',         // trait legacy
        '::getComputedAttribute', // trait legacy accessor
        '::bootHasInlineScopes',       // trait boot hook (#1069)
        '::initializeHasInlineScopes', // trait initialize hook (#1069)
        '::scopeArchived',        // direct legacy
        '::getDisplayNameAttribute', // direct legacy accessor
        '::active',               // trait #[Scope]
        '::published',            // direct #[Scope]
    ];

    /** @var array{findings: list<array{type: string, message: string, severity: string, file_name: string}>, stdout: string, stderr: string}|null */
    private static ?array $run = null;

    #[Test]
    public function unknown_model_attribute_is_reported_through_every_receiver_form_and_stays_silent_on_clean_calls(): void
    {
        $messages = $this->messages($findings = $this->scenarioFindings('UnknownModelAttribute', 'UnknownModelAttribute'));
        $joined = \implode("\n", $messages);

        // One finding per typo'd key, through each of the three Model receiver forms: static::, self::,
        // and a plain external class reference (a name-resolution bug once let `static::`/`self::` bypass
        // the rule). The "clean" call sites and the dynamically declared migration column must stay
        // silent: an exact count proves both that the rule fires and that it does not over-fire.
        $this->assertStringContainsString("'nmae'", $joined, 'static::create() with a typo must be flagged.');
        $this->assertStringContainsString("'unknown_col'", $joined, 'self::create() with a typo must be flagged.');
        $this->assertStringContainsString("'bad_key'", $joined, 'A plain external Model::create() with a typo must be flagged.');
        $this->assertStringNotContainsString("'real_col'", $joined, 'A dynamically declared migration column must not be flagged.');

        // #1679: creation calls on builder and relation receivers. Each `typo_*` key names the
        // receiver::method() and the model the message must carry.
        foreach (self::EXPECTED_CREATION_PATH_FINDINGS as $key => [$prefix, $model]) {
            $this->assertMatchesRegularExpression(
                '/^' . \preg_quote($prefix, '/') . '\(\) is assigned an unknown attribute \'' . \preg_quote($key, '/') . '\'\..* of ' . $model . ' — check for a typo\.$/m',
                $joined,
                "'{$key}' must be flagged as {$prefix}() against {$model}.",
            );
        }

        // Receivers/methods the rule must decline: update() bypasses fill(), pivot (second-arg) keys,
        // unresolved templates, ambiguous receivers, unlisted methods all carry a `silent_` prefix.
        $this->assertStringNotContainsString("'custom_fields'", $joined, 'A model with a trait-widened guard must not be flagged.');
        $this->assertStringNotContainsString("'silent_", $joined, 'A declined receiver/method must not be flagged.');

        $this->assertCount(3 + \count(self::EXPECTED_CREATION_PATH_FINDINGS), $findings, "Unexpected UnknownModelAttribute finding count, got:\n{$joined}");
        // Experimental mode enforces the rule, so every finding is an error rather than the default info.
        $this->assertSame(\array_fill(0, \count($findings), 'error'), \array_column($findings, 'severity'));
    }

    #[Test]
    public function undefined_model_relation_reports_invalid_default_relations_and_accepts_inherited_and_trait_methods(): void
    {
        $messages = $this->messages($this->scenarioFindings('UndefinedModelRelationDefaults', 'UndefinedModelRelation'));

        $this->assertCount(5, $messages, \implode("\n", $messages));

        foreach ([
            ['misspelledRelation', 'BadDefaultsModel', 'with'],
            ['misspelledCount', 'BadDefaultsModel', 'withCount'],
            ['childRelation', 'SiblingWithoutInheritedRelation', 'with'],
            ['numericMissing', 'NumericKeyedDefaultsModel', 'with'],
            ['comments  AS  total', 'MalformedAliasModel', 'withCount'],
        ] as [$relation, $model, $property]) {
            $class = 'RelationDefaultsFixture\\Models\\' . $model;
            $this->assertContains("Relation '{$relation}' from {$class}::\${$property} is not defined on {$class}.", $messages);
        }
    }

    #[Test]
    public function autoloading_a_class_named_by_an_analyzed_type_does_not_crash_or_drop_the_plugin(): void
    {
        // Regression guard for `\is_a($class, X::class, true)` on class names from analyzed types
        // (#1253, #1652): it autoloads the class, and a load-time deprecation (`Deprecated*.php`) crashes
        // the whole run or, where caught, drops a cast or the whole plugin, failing a
        // `@psalm-check-type-exact` in the scenario's usage.php. The run-wide crash check is in psalmRun().
        $checkTypeFailures = \array_filter(
            $this->psalmRun()['findings'],
            static fn(array $finding): bool => $finding['type'] === 'CheckType',
        );

        $this->assertSame([], \array_values($checkTypeFailures), 'A @psalm-check-type-exact failed.');
    }

    #[Test]
    public function indirectly_dispatched_eloquent_methods_are_suppressed_but_a_plain_unused_method_is_not(): void
    {
        // The plain control method is genuinely never called and must stay reported, proving the
        // suppressor narrows to indirectly-dispatched methods rather than silencing every unused method
        // on the model. The exact count also guarantees nothing else leaks.
        $messages = $this->messages($this->scenarioFindings('SuppressScopeUnusedCode', 'PossiblyUnusedMethod', 'UnusedMethod'));
        $joined = \implode("\n", $messages);

        $this->assertCount(1, $messages, "Expected exactly one unused-method finding, got:\n{$joined}");
        $this->assertStringContainsString('TraitScopeModel::helperNonScope', $joined);

        foreach (self::SUPPRESSED_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $joined, "Indirectly-dispatched method {$marker} must be suppressed, not reported as unused.");
        }
    }

    #[Test]
    public function dynamic_where_resolves_schema_only_columns_through_every_call_form(): void
    {
        // Static, instance, relation-chain, multi-segment and custom-builder-wins forms; the model has a
        // migration and NO `@property` docblock.
        $findings = $this->scenarioFindings('DynamicWhereSchemaColumns', ...self::DYNAMIC_WHERE_ISSUES);
        $positive = \array_filter($findings, static fn(array $f): bool => \str_ends_with($f['file_name'], '/Positive.php'));

        $this->assertSame([], \array_values($positive), 'Positive.php must report no resolution issues.');
    }

    #[Test]
    public function dynamic_where_still_reports_unknown_and_unreachable_columns(): void
    {
        $findings = $this->scenarioFindings('DynamicWhereSchemaColumns', ...self::DYNAMIC_WHERE_ISSUES);
        $messages = [];
        foreach ($findings as $finding) {
            if (\str_ends_with($finding['file_name'], '/Negative.php')) {
                $messages[] = $finding['type'] . ': ' . $finding['message'];
            }
        }

        $joined = \implode("\n", $messages);

        $this->assertCount(2, \array_filter($messages, static fn(string $m): bool => \str_starts_with($m, 'UndefinedMagicMethod')), $joined);
        // Unknown column, unreachable column, and a related-model union where one alternative lacks the
        // column, or a receiver union with one such alternative: none is claimed, so each returned chain stays mixed.
        $this->assertCount(4, \array_filter($messages, static fn(string $m): bool => \str_starts_with($m, 'MixedReturnStatement')), $joined);
        $this->assertStringContainsString('UndefinedMagicMethod: Magic method DynamicWhereFixture\Models\UserFilter::wherenosuchcolumn', $joined);
        $this->assertStringContainsString('UndefinedMagicMethod: Magic method DynamicWhereFixture\Models\UserFilter::whereline1', $joined);
    }

    #[Test]
    public function unresolvable_appended_attribute_reports_only_the_unbacked_append(): void
    {
        $findings = $this->scenarioFindings('UnresolvableAppendedModelAttribute', 'UnresolvableAppendedModelAttribute');
        $messages = $this->messages($findings);
        $joined = \implode("\n", $messages);

        // Exactly one finding: the unbacked append fires; the accessor-, cast-, trait-cast- (a class cast
        // a trait initializer merges at construction), and hidden-backed controls stay silent.
        $this->assertCount(1, $findings, "Expected exactly one UnresolvableAppendedModelAttribute finding, got:\n{$joined}");
        $this->assertStringContainsString('UnbackedAppendModel', $messages[0]);
        $this->assertStringContainsString("'avatar_url'", $messages[0]);
        $this->assertSame('error', $findings[0]['severity']);

        foreach (['AccessorBackedAppendModel', 'CastBackedAppendModel', 'TraitCastBackedModel', 'HiddenAppendModel'] as $clean) {
            $this->assertStringNotContainsString($clean, $joined, "{$clean} has a backed/hidden append and must not be reported.");
        }
    }

    #[Test]
    public function unconfigured_filesystem_disk_reports_only_unconfigured_disk_names(): void
    {
        // Exact set: each flagged shape (literal, enum case, class constant) fires; the configured
        // disk, falsy, dotted, dynamic, union, int-enum and DI-manager calls stay silent.
        $this->assertEqualsCanonicalizing(
            [
                "Disk 's3-old' is not configured in filesystems.disks",
                "Disk 's3-old' is not configured in filesystems.disks",
                "Disk 'missing-alias' is not configured in filesystems.disks",
                "Disk 'publik' is not configured in filesystems.disks, did you mean 'public'?",
                "Disk 'tenant' is not configured in filesystems.disks",
                "Disk 'legacy' is not configured in filesystems.disks",
                "Disk 'old-archive' is not configured in filesystems.disks",
                "Disk 'old-archive' is not configured in filesystems.disks",
            ],
            $this->messages($this->scenarioFindings('UnconfiguredFilesystemDisk', 'UnconfiguredFilesystemDisk')),
        );
    }

    #[Test]
    public function unregistered_route_name_is_reported_through_every_covered_receiver_and_stays_silent_otherwise(): void
    {
        $findings = $this->scenarioFindings('UnregisteredRouteName', 'UnregisteredRouteName');
        $joined = \implode("\n", $this->messages($findings));

        // 8 positional (route(), to_route(), URL::route/signedRoute/temporarySignedRoute, Redirect::route(),
        // redirect()->route(), url()->route() on the Contracts\UrlGenerator url() returns with no path)
        // + 3 named-argument (route(absolute:, name:) with the name at offset 1, to_route(route:),
        // redirect()->route(route:)) + 2 non-literal names (string-backed enum case, class constant).
        // An exact count proves both full receiver coverage and that the clean, spread and dynamic
        // shapes do not over-fire.
        $this->assertCount(13, $findings, "Expected exactly 13 UnregisteredRouteName findings, got:\n{$joined}");
        $this->assertStringNotContainsString("'dashboard'", $joined);
        $this->assertStringNotContainsString("'posts.show'", $joined);
        $this->assertSame(\array_fill(0, 13, 'error'), \array_column($findings, 'severity'));
    }

    #[Test]
    public function a_warm_up_failure_reaches_stderr_under_no_progress(): void
    {
        // `--no-progress` selects Psalm's VoidProgress, which turns Progress::write()/warning() into a
        // no-op, so before the fix a warm-up failure looked identical to a clean run. The fix writes to
        // the STDERR constant, which bypasses output buffering, so only a real subprocess can observe it.
        // UnknownModelAttribute/Models/WarmUpCrashModel throws from getTable(). Before
        // ModelPropertyHandler::resolveTableName() caught \Throwable, that also propagated out of
        // registerWriteTypesForColumns() and killed the whole run, which psalmRun() asserts against.
        $stderr = $this->psalmRun()['stderr'];

        $this->assertStringContainsString(
            "Laravel plugin: ModelMetadataRegistry warm-up failed for 'KnownAttributeFixture\\Models\\WarmUpCrashModel'",
            $stderr,
            "The warm-up failure warning must reach stderr even under --no-progress.\nFull stderr:\n{$stderr}",
        );
    }

    /**
     * @param string ...$types issue types to keep
     * @return list<array{type: string, message: string, severity: string, file_name: string}>
     */
    private function scenarioFindings(string $scenario, string ...$types): array
    {
        return \array_values(\array_filter(
            $this->psalmRun()['findings'],
            static fn(array $finding): bool => \str_starts_with($finding['file_name'], "app/{$scenario}/")
                && \in_array($finding['type'], $types, true),
        ));
    }

    /**
     * @param list<array{type: string, message: string, severity: string, file_name: string}> $findings
     * @return list<string>
     */
    private function messages(array $findings): array
    {
        return \array_column($findings, 'message');
    }

    /**
     * @return array{findings: list<array{type: string, message: string, severity: string, file_name: string}>, stdout: string, stderr: string}
     */
    private function psalmRun(): array
    {
        if (self::$run !== null) {
            return self::$run;
        }

        $projectRoot = \dirname(__DIR__, 3);
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found, run composer install.');

        $cacheDir = \sys_get_temp_dir() . '/psalm-laravel-emission-app-' . \bin2hex(\random_bytes(6));
        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--scan-threads=1', '--no-progress', '--show-info=true', '--output-format=json'],
            __DIR__ . '/Fixtures/EmissionApp',
            ['XDG_CACHE_HOME' => $cacheDir, 'TMPDIR' => $cacheDir . '/'],
        );
        $process->setTimeout(300);

        try {
            // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
            $process->run();
        } finally {
            (new Filesystem())->deleteDirectory($cacheDir);
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        foreach (['crashed due to an uncaught Throwable', 'Uncaught'] as $crashMarker) {
            $this->assertStringNotContainsString($crashMarker, $stdout . "\n" . $stderr, "Psalm crashed instead of completing analysis.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        }

        $decoded = \json_decode($stdout, true);
        $this->assertIsArray($decoded, "Psalm did not return a JSON array, analysis likely crashed.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && isset($finding['type'], $finding['message'], $finding['severity'], $finding['file_name'])) {
                $findings[] = [
                    'type' => (string) $finding['type'],
                    'message' => (string) $finding['message'],
                    'severity' => (string) $finding['severity'],
                    'file_name' => (string) $finding['file_name'],
                ];
            }
        }

        return self::$run = ['findings' => $findings, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
