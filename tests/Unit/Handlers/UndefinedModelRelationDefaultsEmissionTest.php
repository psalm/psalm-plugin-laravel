<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\UndefinedModelRelationHandler;
use Symfony\Component\Process\Process;

/**
 * Metadata warm-up requires autoloadable models, so this exercises the full plugin lifecycle in a
 * dedicated fixture project rather than a PHPT source file.
 */
#[CoversClass(UndefinedModelRelationHandler::class)]
final class UndefinedModelRelationDefaultsEmissionTest extends TestCase
{
    #[Test]
    public function it_reports_invalid_default_relations_and_accepts_inherited_and_trait_methods(): void
    {
        $findings = $this->runPsalmAndCollectFindings();
        $messages = \array_column($findings, 'message');

        $this->assertCount(3, $findings, \implode("\n", $messages));
        $this->assertContains(
            "Relation 'misspelledRelation' from " . \RelationDefaultsFixture\Models\BadDefaultsModel::class . '::$with is not defined on ' . \RelationDefaultsFixture\Models\BadDefaultsModel::class . '.',
            $messages,
        );
        $this->assertContains(
            "Relation 'misspelledCount' from " . \RelationDefaultsFixture\Models\BadDefaultsModel::class . '::$withCount is not defined on ' . \RelationDefaultsFixture\Models\BadDefaultsModel::class . '.',
            $messages,
        );
        $this->assertContains(
            "Relation 'childRelation' from " . \RelationDefaultsFixture\Models\SiblingWithoutInheritedRelation::class . '::$with is not defined on ' . \RelationDefaultsFixture\Models\SiblingWithoutInheritedRelation::class . '.',
            $messages,
        );
    }

    /** @return list<array{type: string, message: string}> */
    private function runPsalmAndCollectFindings(): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/UndefinedModelRelationDefaults';
        $process = new Process(
            [\PHP_BINARY, $projectRoot . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            $fixtureDir,
        );
        $process->setTimeout(300);
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, $process->getErrorOutput());

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && ($finding['type'] ?? null) === 'UndefinedModelRelation') {
                $findings[] = ['type' => $finding['type'], 'message' => (string) $finding['message']];
            }
        }

        return $findings;
    }
}
