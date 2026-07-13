<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

/**
 * Status of one independently-computed metadata section.
 *
 * @psalm-immutable
 * @internal
 */
final readonly class ModelMetadataSectionStatus
{
    private function __construct(
        public MetadataSectionState $state,
        public ?string $reason,
    ) {}

    /** @psalm-pure */
    public static function complete(): self
    {
        return new self(MetadataSectionState::Complete, null);
    }

    /** @psalm-pure */
    public static function unavailable(string $reason): self
    {
        return new self(MetadataSectionState::Unavailable, $reason);
    }

    public static function failed(\Throwable $throwable): self
    {
        return new self(
            MetadataSectionState::Failed,
            "{$throwable->getMessage()} at {$throwable->getFile()}:{$throwable->getLine()}",
        );
    }

    /** @psalm-pure */
    public static function unavailableBecause(ModelMetadataSection $dependency, self $status): self
    {
        $reason = "depends on {$dependency->value}, which is {$status->state->value}";
        if ($status->reason !== null) {
            $reason .= ': ' . $status->reason;
        }

        return self::unavailable($reason);
    }

    /** @psalm-mutation-free */
    public function isComplete(): bool
    {
        return $this->state === MetadataSectionState::Complete;
    }
}
