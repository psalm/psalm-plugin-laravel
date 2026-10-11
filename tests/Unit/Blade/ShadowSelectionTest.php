<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\LaravelPlugin\Blade\ShadowSelection;

#[CoversClass(ShadowSelection::class)]
final class ShadowSelectionTest extends TestCase
{
    #[Test]
    public function selection_offsets_are_relative_to_the_snippet(): void
    {
        $selection = ShadowSelection::of($this->location('echo e($x);', [107, 109], [100, 111]));

        $this->assertNotNull($selection);
        $this->assertSame('echo e($x);', $selection->snippet);
        $this->assertSame(7, $selection->start);
        $this->assertSame(9, $selection->end);
    }

    /** Psalm computes the snippet lazily from file contents; any failure there is a decline. */
    #[Test]
    public function an_unreadable_location_yields_null(): void
    {
        $this->assertNull(ShadowSelection::of($this->location(null, [0, 0], [0, 0])));
    }

    /**
     * @param array{int, int} $selection
     * @param array{int, int} $snippet
     */
    private function location(?string $text, array $selection, array $snippet): CodeLocation
    {
        return new class ($text, $selection, $snippet) extends CodeLocation {
            /**
             * @param array{int, int} $selection
             * @param array{int, int} $snippetBounds
             */
            public function __construct(
                private readonly ?string $snippetText,
                private readonly array $selection,
                private readonly array $snippetBounds,
            ) {}

            #[\Override]
            public function getSnippet(): string
            {
                return $this->snippetText ?? throw new \RuntimeException('file contents unavailable');
            }

            #[\Override]
            public function getSelectionBounds(): array
            {
                return $this->selection;
            }

            #[\Override]
            public function getSnippetBounds(): array
            {
                return $this->snippetBounds;
            }
        };
    }
}
