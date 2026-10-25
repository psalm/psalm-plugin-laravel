<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every issue links to its docs page from the Psalm output, so a missing or misnamed page is a
 * dead link the user hits.
 */
#[CoversNothing]
final class IssueDocumentationTest extends TestCase
{
    #[Test]
    public function every_issue_class_documentation_url_points_to_an_existing_page(): void
    {
        $files = \glob(\dirname(__DIR__, 2) . '/src/Issues/*.php');
        $this->assertNotEmpty($files, 'src/Issues/*.php discovery found nothing; the source shape changed');

        foreach ($files as $file) {
            $name = \basename($file, '.php');
            $fqcn = "Psalm\\LaravelPlugin\\Issues\\{$name}";

            $this->assertSame(
                "https://psalm.github.io/psalm-plugin-laravel/issues/{$name}/",
                \constant("{$fqcn}::DOCUMENTATION_URL"),
                "{$fqcn}::DOCUMENTATION_URL does not match its own issue page",
            );
            $this->assertFileExists(\dirname(__DIR__, 2) . "/docs/issues/{$name}.md");
        }
    }
}
