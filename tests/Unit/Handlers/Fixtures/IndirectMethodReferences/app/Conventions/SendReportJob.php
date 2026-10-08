<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use Illuminate\Contracts\Queue\ShouldQueue;
use IndirectMethodReferencesFixture\Dependencies\JobHandleDependency;

final class SendReportJob implements ShouldQueue
{
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
}
