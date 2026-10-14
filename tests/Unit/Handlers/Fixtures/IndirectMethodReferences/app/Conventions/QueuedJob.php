<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The `make:job` scaffold trait composes Dispatchable, and `dispatch()` is the only caller of the constructor. */
final class SendReportJob implements ShouldBeUnique, ShouldQueue
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

/** ShouldQueue without a bus trait is shaped like a listener: Laravel passes the event positionally, nothing is injected. */
final class QueuedListener implements ShouldQueue
{
    public function __construct(private readonly string $label = 'listener') {}

    public function handle(QueuedListenerEvent $event): void
    {
        \assert($this->label !== "" && $event::class !== "");
    }
}

final class QueuedListenerEvent
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}
