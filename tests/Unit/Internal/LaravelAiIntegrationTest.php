<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the laravel/ai stub tree itself: it is loaded as one flat set, so two
 * files declaring the same symbol would make coverage depend on load order.
 */
#[CoversNothing]
final class LaravelAiIntegrationTest extends TestCase
{
    #[Test]
    public function no_two_laravel_ai_stub_files_declare_the_same_symbol(): void
    {
        $declarations = $this->declarationFiles();

        // A parser that silently finds nothing would make the duplicate check below vacuous.
        $this->assertArrayHasKey('Laravel\\Ai\\Promptable', $declarations);
        $this->assertArrayHasKey('Laravel\\Ai\\agent', $declarations);

        $duplicates = [];
        foreach ($declarations as $name => $files) {
            if (\count($files) > 1) {
                $duplicates[] = $name . ': ' . \implode(', ', $files);
            }
        }

        $this->assertSame(
            [],
            $duplicates,
            'Each laravel/ai symbol must be stubbed in exactly one file; otherwise coverage depends on stub load order.',
        );
    }

    /**
     * @return array<string, list<string>> Fully-qualified declaration name => relative stub files declaring it
     */
    private function declarationFiles(): array
    {
        $directory = \dirname(__DIR__, 3) . '/stubs/integrations/laravel-ai';
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $declarations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'phpstub') {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = \str_replace(\DIRECTORY_SEPARATOR, '/', \substr($path, \strlen($directory) + 1));
            $source = \file_get_contents($path);

            if (!\is_string($source)) {
                throw new \RuntimeException("Unable to read {$path}.");
            }

            $statements = $parser->parse($source);
            if (!\is_array($statements)) {
                throw new \RuntimeException("Unable to parse {$path}.");
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $statements = $traverser->traverse($statements);

            $declarationNodes = [
                ...$finder->findInstanceOf($statements, Node\Stmt\ClassLike::class),
                ...$finder->findInstanceOf($statements, Node\Stmt\Function_::class),
            ];

            foreach ($declarationNodes as $declaration) {
                $name = $declaration->namespacedName;
                if (!$name instanceof Node\Name) {
                    continue;
                }

                $declarations[$name->toString()][] = $relativePath;
            }
        }

        return $declarations;
    }
}
