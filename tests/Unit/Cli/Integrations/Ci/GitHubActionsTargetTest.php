<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli\Integrations\Ci;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\CiPlan;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\GitHubActionsTarget;

#[CoversClass(GitHubActionsTarget::class)]
#[CoversClass(CiPlan::class)]
final class GitHubActionsTargetTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-gha-' . \uniqid('', true);
        if (! \mkdir($this->tempDir) && ! \is_dir($this->tempDir)) {
            throw new \RuntimeException(\sprintf('Failed to create temp directory %s', $this->tempDir));
        }
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->tempDir);
    }

    #[Test]
    public function id_is_stable(): void
    {
        $this->assertSame('github', (new GitHubActionsTarget())->id());
    }

    #[Test]
    public function display_name_is_human_readable(): void
    {
        $this->assertSame('GitHub Actions', (new GitHubActionsTarget())->displayName());
    }

    #[Test]
    public function detects_github_directory(): void
    {
        $target = new GitHubActionsTarget();

        $this->assertFalse($target->detect($this->tempDir));

        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . '.github');

        $this->assertTrue($target->detect($this->tempDir));
    }

    #[Test]
    public function plan_returns_destination_under_workflows_dir(): void
    {
        $plan = (new GitHubActionsTarget())->plan($this->tempDir);

        $expected = $this->tempDir . \DIRECTORY_SEPARATOR . '.github'
            . \DIRECTORY_SEPARATOR . 'workflows'
            . \DIRECTORY_SEPARATOR . 'psalm.yml';

        $this->assertSame($expected, $plan->path);
        $this->assertFalse($plan->targetExists);
    }

    #[Test]
    public function plan_marks_target_existing_when_file_is_present(): void
    {
        $workflowsDir = $this->tempDir . \DIRECTORY_SEPARATOR . '.github' . \DIRECTORY_SEPARATOR . 'workflows';
        \mkdir($workflowsDir, 0755, true);
        \file_put_contents($workflowsDir . \DIRECTORY_SEPARATOR . 'psalm.yml', 'name: old');

        $plan = (new GitHubActionsTarget())->plan($this->tempDir);

        $this->assertTrue($plan->targetExists);
    }

    #[Test]
    public function plan_reads_contents_from_bundled_template(): void
    {
        $plan = (new GitHubActionsTarget())->plan($this->tempDir);

        // Assert structural contents rather than whole file equality so doc
        // tweaks to the template don't break the test.
        $this->assertStringContainsString('name: Psalm', $plan->contents);
        $this->assertStringContainsString('types:', $plan->contents);
        $this->assertStringContainsString('security:', $plan->contents);
        $this->assertStringContainsString('shivammathur/setup-php', $plan->contents);
        $this->assertStringContainsString('github/codeql-action/upload-sarif', $plan->contents);
        $this->assertStringContainsString('--taint-analysis', $plan->contents);
    }

    #[Test]
    public function plan_reads_from_override_when_provided(): void
    {
        $templatePath = $this->tempDir . \DIRECTORY_SEPARATOR . 'custom.yml';
        \file_put_contents($templatePath, 'name: Custom Override');

        $plan = (new GitHubActionsTarget($templatePath))->plan($this->tempDir);

        $this->assertSame('name: Custom Override', $plan->contents);
    }

    #[Test]
    public function plan_fails_with_actionable_message_when_template_missing(): void
    {
        $missingPath = $this->tempDir . \DIRECTORY_SEPARATOR . 'does-not-exist.yml';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($missingPath);

        (new GitHubActionsTarget($missingPath))->plan($this->tempDir);
    }

    #[Test]
    public function plan_rejects_empty_template_rather_than_writing_broken_workflow(): void
    {
        // A zero-byte template is a valid file but would produce an empty
        // workflow, which GitHub then rejects with "invalid workflow file".
        // Surface the broken install at add-time instead of at next-push time.
        $emptyTemplate = $this->tempDir . \DIRECTORY_SEPARATOR . 'empty.yml';
        \file_put_contents($emptyTemplate, '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is empty');

        (new GitHubActionsTarget($emptyTemplate))->plan($this->tempDir);
    }

    private function removeRecursively(string $path): void
    {
        if (! \file_exists($path)) {
            return;
        }

        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }

        $entries = \scandir($path);
        if ($entries === false) {
            @\rmdir($path);
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeRecursively($path . \DIRECTORY_SEPARATOR . $entry);
        }

        @\rmdir($path);
    }
}
