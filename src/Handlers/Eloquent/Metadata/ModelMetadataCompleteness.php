<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

/**
 * Which metadata sections are NOT authoritative for a model snapshot.
 *
 * A section is authoritative ("complete") unless it is listed here. Absence from the
 * incomplete set is the complete-AND-possibly-empty case; that is deliberately distinct
 * from a section whose input was unavailable or whose computation failed — both land in
 * the set and both block a negative verdict.
 *
 * The warn-vs-silent decision (failed -> user warning, unavailable -> quiet) is made once
 * at compute time in ModelMetadataRegistryBuilder::computeSection(); consumers only ask the
 * binary "is this section authoritative?", so nothing here stores the failed/unavailable
 * distinction or a reason string.
 *
 * Kept free of Psalm-version-specific types so this backports to the 3.x line.
 *
 * @psalm-immutable
 * @internal
 */
final readonly class ModelMetadataCompleteness
{
    /** @param array<string, true> $incomplete keyed by ModelMetadataSection->value */
    private function __construct(private array $incomplete) {}

    /**
     * Ergonomic default for hand-built healthy metadata in tests and extensions.
     *
     * @psalm-pure
     */
    public static function allComplete(): self
    {
        return new self([]);
    }

    /**
     * @param list<ModelMetadataSection> $incompleteSections
     * @psalm-pure
     */
    public static function withIncomplete(array $incompleteSections): self
    {
        $incomplete = [];
        foreach ($incompleteSections as $section) {
            $incomplete[$section->value] = true;
        }

        return new self($incomplete);
    }

    /** @psalm-mutation-free */
    public function isComplete(ModelMetadataSection ...$sections): bool
    {
        foreach ($sections as $section) {
            if (isset($this->incomplete[$section->value])) {
                return false;
            }
        }

        return true;
    }
}
