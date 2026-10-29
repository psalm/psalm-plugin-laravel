<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the implicit date cast of `getDates()` columns (#2001).
 *
 * Laravel converts every `getDates()` column (the created/updated pair of a timestamped model) through
 * `asDateTime()`, so a model without `@property` or a cast must not read them as the raw schema `string|null`.
 * The psalm-tester harness boots Testbench with no migrations, so only a real Psalm subprocess against a fixture
 * project with migrations sees the schema types.
 */
#[CoversClass(ModelMetadataRegistryBuilder::class)]
#[Group('subprocess')]
final class ModelDatesEmissionTest extends TestCase
{
    /** @var array<string, list<string>> `@psalm-trace` messages per env variant */
    private static array $traces = [];

    /** @return iterable<string, array{string, string}> env value of `MODEL_DATES_DATE`, then the timestamp type */
    public static function dateClasses(): iterable
    {
        yield 'default date class' => ['', 'Illuminate\Support\Carbon|null'];
        yield 'immutable date class' => ['immutable', 'Carbon\CarbonImmutable|null'];
        // A callable factory proves no class: the shared contract answers the Carbon interface.
        yield 'callable date factory' => ['callable', 'Carbon\CarbonInterface|null'];
    }

    #[Test]
    #[DataProvider('dateClasses')]
    public function it_types_model_dates_as_the_configured_date_class(string $mode, string $date): void
    {
        $traces = $this->runPsalm($mode);

        // No `@property`, no cast: `created_at`/`updated_at` are dates all the same, and an explicit cast
        // on another column does not change that.
        $this->assertContains("\$_post_created: {$date}", $traces);
        $this->assertContains("\$_post_updated: {$date}", $traces);
        $this->assertContains("\$_post_published: {$date}", $traces);
        // Renamed created column: a date. `UPDATED_AT = null` puts null into getDates(), and the default
        // `updated_at` column is then no date column.
        $this->assertContains("\$_entry_created: {$date}", $traces);
        // Next to an explicit immutable cast on another column.
        $this->assertContains("\$_invoice_created: {$date}", $traces);
        $this->assertContains("\$_memo_updated: {$date}", $traces);
        // Laravel's own model, whose `read_at` is a declared cast and whose timestamps are implicit.
        $this->assertContains("\$_notification_created: {$date}", $traces);
        $this->assertContains("\$_notification_updated: {$date}", $traces);
        $this->assertContains("\$_notification_read: {$date}", $traces);

        // `$timestamps = false`: getDates() is empty.
        $this->assertContains('$_snapshot_created: null|string', $traces);
        $this->assertContains('$_snapshot_updated: null|string', $traces);
        // `UPDATED_AT = null`: only the renamed created column is a date.
        $this->assertContains('$_entry_updated: null|string', $traces);

        // `created_at` is cast `immutable_datetime`; the cast branch precedes the date branch in Eloquent,
        // whatever the configured date class is. Same for a custom column's immutable cast.
        $this->assertContains('$_memo_created: Carbon\CarbonImmutable|null', $traces);
        $this->assertContains('$_invoice_paid: Carbon\CarbonImmutable|null', $traces);

        // A `getCreatedAtAttribute(): string` accessor leaves no implicit date cast: Laravel calls it with the RAW
        // value before any date conversion, so the date never surfaces. The property read keeps the accessor's type;
        // pluck() (Builder and Collection paths) and max() keep the schema type they had before dates were modeled.
        $this->assertContains('$_journal_created: null|string', $traces);
        $this->assertContains('$_pluck_builder: Illuminate\Support\Collection<int, null|string>', $traces);
        $this->assertContains('$_pluck_collection: Illuminate\Support\Collection<int, null|string>', $traces);
        $this->assertContains('$_max_collection: null|string', $traces);
        $this->assertContains('$_max_builder: null|string', $traces);
        // The same model's `updated_at` has no accessor: the implicit date cast applies to pluck too.
        $this->assertContains("\$_pluck_updated: Illuminate\Support\Collection<int, {$date}>", $traces);
        $this->assertContains("\$_pluck_updated_collection: Illuminate\Support\Collection<int, {$date}>", $traces);

        // Builder::pluck() hydrates only the value column, so its key is the raw driver value: a NOT NULL date
        // column is still a string key. In memory the key is the hydrated date, which Arr::pluck() stringifies with
        // the factory's `toStringFormat` (possibly numeric, hence an int key): no precise key, so array-key.
        $this->assertContains('$_pluck_key_builder: Illuminate\Support\Collection<string, int>', $traces);
        $this->assertContains('$_pluck_key_collection: Illuminate\Support\Collection<array-key, int>', $traces);

        // Only a date key is rewritten; every other cast keeps the stub-compatible key it had before dates were modeled:
        // a `bool` column cast to int is an int key (not the raw `bool`), and an enum or Stringable key stays array-key.
        $this->assertContains('$_key_flag: Illuminate\Support\Collection<int, int>', $traces);
        $this->assertContains('$_key_level: Illuminate\Support\Collection<array-key, int>', $traces);
        $this->assertContains('$_key_sku: Illuminate\Support\Collection<array-key, int>', $traces);
        $this->assertContains('$_key_sku_collection: Illuminate\Support\Collection<array-key, int>', $traces);

        // A set-only `Attribute` is no getter: Laravel still date-converts the column.
        $this->assertContains("\$_draft_created: {$date}", $traces);

        // An `Attribute` factory that throws on the warm-up instance leaves the other casts typed.
        $this->assertContains('$_moody_active: bool|null', $traces);
    }

    /**
     * One Psalm process per date-class variant; every case of a variant reads the same run.
     *
     * @return list<string> messages of every `@psalm-trace`
     */
    private function runPsalm(string $mode): array
    {
        if (isset(self::$traces[$mode])) {
            return self::$traces[$mode];
        }

        $fixtureDir = __DIR__ . '/Fixtures/ModelDates';
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--show-info=true', '--output-format=json'],
            $fixtureDir,
            ['MODEL_DATES_DATE' => $mode],
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

        return self::$traces[$mode] = $traces;
    }
}
