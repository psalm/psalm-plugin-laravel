<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

/**
 * Immutable completeness map for a model metadata snapshot.
 *
 * A complete section may contain no data; that is deliberately distinct from an
 * unavailable input and from an attempted computation that failed.
 *
 * @psalm-immutable
 * @internal
 */
final readonly class ModelMetadataCompleteness
{
    /**
     * @param array<non-empty-string, ModelMetadataSectionStatus> $statuses
     */
    private function __construct(private array $statuses) {}

    /**
     * Ergonomic default for healthy hand-built metadata in tests and extensions.
     *
     * @psalm-pure
     */
    public static function allComplete(): self
    {
        $statuses = [];
        foreach (ModelMetadataSection::cases() as $section) {
            $statuses[$section->value] = ModelMetadataSectionStatus::complete();
        }

        return new self($statuses);
    }

    /**
     * @param array<non-empty-string, ModelMetadataSectionStatus> $statuses
     * @psalm-pure
     */
    public static function fromStatuses(array $statuses): self
    {
        foreach (ModelMetadataSection::cases() as $section) {
            $statuses[$section->value] ??= ModelMetadataSectionStatus::unavailable('section was not evaluated');
        }

        return new self($statuses);
    }

    /** @psalm-mutation-free */
    public function status(ModelMetadataSection $section): ModelMetadataSectionStatus
    {
        return $this->statuses[$section->value];
    }

    /**
     * @psalm-api
     * @psalm-mutation-free
     */
    public function withStatus(ModelMetadataSection $section, ModelMetadataSectionStatus $status): self
    {
        return new self([...$this->statuses, $section->value => $status]);
    }

    /** @psalm-mutation-free */
    public function isComplete(ModelMetadataSection ...$sections): bool
    {
        foreach ($sections as $section) {
            if (!$this->status($section)->isComplete()) {
                return false;
            }
        }

        return true;
    }
}
