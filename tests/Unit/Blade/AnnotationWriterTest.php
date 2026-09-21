<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationCollector;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationWriter;
use Psalm\LaravelPlugin\Blade\ContractVar;
use Psalm\LaravelPlugin\Blade\ViewDataContract;
use Psalm\Type;

#[CoversClass(AnnotationWriter::class)]
final class AnnotationWriterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        AnnotationCollector::reset();

        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-annotate-' . \uniqid('', true);

        if (!\mkdir($this->tempDir) && !\is_dir($this->tempDir)) {
            throw new \RuntimeException("Failed to create temp directory {$this->tempDir}");
        }
    }

    protected function tearDown(): void
    {
        AnnotationCollector::reset();

        foreach (\glob($this->tempDir . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->tempDir);
    }

    #[Test]
    public function plans_the_resolved_type_for_a_read_variable_every_producer_agreed_on(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $plan = AnnotationWriter::plan('home', new ViewDataContract([], false, ['title'], false));

        $this->assertSame(['title' => 'string'], $plan);
    }

    #[Test]
    public function plans_mixed_for_a_read_variable_no_producer_could_type(): void
    {
        $plan = AnnotationWriter::plan('home', new ViewDataContract([], false, ['title'], false));

        $this->assertSame(['title' => 'mixed'], $plan);
    }

    #[Test]
    public function leaves_out_a_variable_the_template_already_declares(): void
    {
        $contract = new ViewDataContract(
            ['title' => new ContractVar('title', 'string', 1, false)],
            false,
            ['title', 'body'],
            false,
        );

        $this->assertSame(['body' => 'mixed'], AnnotationWriter::plan('home', $contract));
    }

    #[Test]
    public function skips_a_template_whose_compiled_body_hides_which_names_it_reads(): void
    {
        // `@props` and `@aware` compile to `$$name`; a partial read set must never be annotated.
        $this->assertSame([], AnnotationWriter::plan('home', new ViewDataContract([], false, ['title'], true)));
    }

    #[Test]
    public function writes_the_contract_block_into_the_template(): void
    {
        $path = $this->template("<h1>{{ \$title }}</h1>\n");

        $diff = AnnotationWriter::apply($path, ['title' => 'string'], false);

        $this->assertNotNull($diff);
        $this->assertSame("{{-- @var string \$title --}}\n<h1>{{ \$title }}</h1>\n", \file_get_contents($path));
    }

    #[Test]
    public function leaves_the_template_untouched_under_dry_run_but_still_reports_the_diff(): void
    {
        $path = $this->template("<h1>{{ \$title }}</h1>\n");
        $before = \md5_file($path);

        $diff = AnnotationWriter::apply($path, ['title' => 'string'], true);

        $this->assertSame($before, \md5_file($path));
        $this->assertNotNull($diff);
        $this->assertStringContainsString('@@ -0,0 +1,1 @@', $diff);
        $this->assertStringContainsString("+{{-- @var string \$title --}}", $diff);
    }

    #[Test]
    public function a_second_write_of_the_same_plan_changes_nothing(): void
    {
        $path = $this->template("<h1>{{ \$title }}</h1>\n");

        AnnotationWriter::apply($path, ['title' => 'string'], false);
        $afterFirst = \md5_file($path);

        $this->assertNull(AnnotationWriter::apply($path, ['title' => 'string'], false));
        $this->assertSame($afterFirst, \md5_file($path));
    }

    #[Test]
    public function reports_a_template_it_cannot_read_rather_than_failing_silently(): void
    {
        $this->expectException(\RuntimeException::class);

        AnnotationWriter::apply($this->tempDir . \DIRECTORY_SEPARATOR . 'absent.blade.php', ['t' => 'string'], true);
    }

    private function template(string $contents): string
    {
        $path = $this->tempDir . \DIRECTORY_SEPARATOR . 'page.blade.php';
        \file_put_contents($path, $contents);

        return $path;
    }
}
