<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendReportJob implements ShouldQueue
{
    use Queueable;

    /** Read off the job by the queue, not by user code. */
    public int $tries = 3;

    public function __construct(
        private readonly string $reportId,
        private readonly string $failureNote,
    ) {}

    public function handle(JobHandleDependency $dependency): void
    {
        \assert($this->reportId !== '' && \is_object($dependency));
    }

    public function failed(\Throwable $exception): void
    {
        \assert($this->failureNote !== '' && $exception->getMessage() !== '');
    }

    public function uniqueId(): string
    {
        return $this->reportId;
    }
}

final class JobHandleDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}
