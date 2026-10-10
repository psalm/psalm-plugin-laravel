<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Nothing builds or dispatches this job, and a class-conditional edge from a dead class never fires. */
final class DeadJob implements ShouldQueue
{
    public function __construct(private readonly string $payload) {}

    public function handle(): void
    {
        \assert($this->payload !== '');
    }
}

/** Referenced, but `$next` has no native Closure type, so it is no pipe; and it is not queued, so `$tries` is no hook. */
final class NotAPipe
{
    public int $tries = 1;

    public function handle(string $request, $next): void
    {
        \assert($request !== '' && \is_callable($next));
    }
}

trait PublicPipeTrait
{
    /** @param \Closure(string): string $next */
    public function handle(string $request, \Closure $next): string
    {
        return $next($request);
    }
}

/** The adaptation lives here, on the class that uses the trait, not on the subclass inheriting the method. */
abstract class DemotedPipeBase
{
    use PublicPipeTrait {
        handle as protected;
    }
}

final class DemotedPipe extends DemotedPipeBase
{
    public function __construct(DemotedPipeDependency $dependency)
    {
        \assert(\is_object($dependency));
    }
}

final class DemotedPipeDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

/** Laravel reads uniqueId() only for ShouldBeUnique jobs, so on a plain queued job it is an ordinary unused method. */
final class NonUniqueQueuedJob implements ShouldQueue
{
    public function uniqueId(): int
    {
        return 1;
    }

    public function handle(): void
    {
        \assert(true);
    }
}
