<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotateRequest;

#[CoversClass(AnnotateRequest::class)]
final class AnnotateRequestTest extends TestCase
{
    private string $controlFile;

    protected function setUp(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'psalm-laravel-annotate-request');

        if ($path === false) {
            throw new \RuntimeException('Failed to create a control file.');
        }

        $this->controlFile = $path;
    }

    protected function tearDown(): void
    {
        \putenv(AnnotateRequest::ENV_VAR);
        @\unlink($this->controlFile);
    }

    #[Test]
    public function reads_a_control_file_this_cli_wrote(): void
    {
        $this->control(['psalm-laravel-annotate' => 1, 'dryRun' => true]);

        $request = AnnotateRequest::fromEnvironment();

        $this->assertInstanceOf(AnnotateRequest::class, $request);
        $this->assertTrue($request->dryRun);
    }

    #[Test]
    public function refuses_a_json_file_that_carries_no_marker(): void
    {
        // A leaked environment variable pointing at, say, a composer.json must not arm the codemod:
        // it would both rewrite the view tree and overwrite the file it names.
        $this->control(['name' => 'acme/project', 'dryRun' => true]);

        $this->assertNull(AnnotateRequest::fromEnvironment());
    }

    #[Test]
    public function refuses_a_file_that_is_not_json_at_all(): void
    {
        \file_put_contents($this->controlFile, "#!/bin/sh\necho hello\n");
        \putenv(AnnotateRequest::ENV_VAR . '=' . $this->controlFile);

        $this->assertNull(AnnotateRequest::fromEnvironment());
    }

    #[Test]
    public function refuses_an_environment_variable_naming_nothing(): void
    {
        \putenv(AnnotateRequest::ENV_VAR . '=' . $this->controlFile . '-absent');

        $this->assertNull(AnnotateRequest::fromEnvironment());
    }

    #[Test]
    public function is_absent_when_the_environment_variable_is_not_set(): void
    {
        \putenv(AnnotateRequest::ENV_VAR);

        $this->assertNull(AnnotateRequest::fromEnvironment());
    }

    #[Test]
    public function refuses_to_publish_over_a_file_that_lost_its_marker(): void
    {
        // Re-checked at write time, not just at read time: the control file is named by an
        // environment variable, and the path can be repointed while the analysis runs.
        $this->control(['psalm-laravel-annotate' => 1, 'dryRun' => false]);

        $request = AnnotateRequest::fromEnvironment();
        $this->assertInstanceOf(AnnotateRequest::class, $request);

        $foreign = (string) \json_encode(['name' => 'acme/project']);
        \file_put_contents($this->controlFile, $foreign);

        $request->publish(['a.blade.php' => ['title']], [], '');

        $this->assertSame($foreign, \file_get_contents($this->controlFile));
    }

    #[Test]
    public function publishes_the_result_back_through_the_control_file(): void
    {
        $this->control(['psalm-laravel-annotate' => 1, 'dryRun' => false]);

        $request = AnnotateRequest::fromEnvironment();
        $this->assertInstanceOf(AnnotateRequest::class, $request);

        $request->publish(['a.blade.php' => ['title']], [], "+line\n");

        $decoded = \json_decode((string) \file_get_contents($this->controlFile), true);

        $this->assertIsArray($decoded);
        $this->assertSame(['a.blade.php' => ['title']], $decoded['changed'] ?? null);
    }

    /** @param array<string, mixed> $contents */
    private function control(array $contents): void
    {
        \file_put_contents($this->controlFile, (string) \json_encode($contents));
        \putenv(AnnotateRequest::ENV_VAR . '=' . $this->controlFile);
    }
}
