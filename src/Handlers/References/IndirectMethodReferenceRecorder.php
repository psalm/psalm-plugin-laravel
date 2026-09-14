<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\References;

use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\MethodIdentifier;

/**
 * Writes Laravel's statically-proven indirect calls into Psalm's code-use graph via
 * {@see Codebase::addReferenceToFunctionLike()}.
 *
 * Keeping this mutation in one small collaborator makes it difficult for handlers to
 * accidentally mark a method as a return-value reference or to mutate Psalm's method
 * storage instead of its supported reference graph.
 *
 * The two edge kinds carry different `$is_return_value_used` truth: {@see record()} is a
 * synthetic method-to-method edge for a container-resolved constructor, whose return value (the
 * constructed instance) Laravel discards after injecting it, so it stays `false`. {@see
 * recordFileReference()} is used only for relationship methods and already-proven framework
 * entrypoints, whose return value Laravel's dispatcher (eager-loading, the router, the console
 * kernel) actually consumes, so it is `true`.
 *
 * @internal
 */
final class IndirectMethodReferenceRecorder
{
    public static function record(Codebase $codebase, MethodIdentifier $callingMethodId, MethodIdentifier $methodId): void
    {
        if ($codebase->find_unused_code === null) {
            return;
        }

        $context = new Context();
        $context->calling_method_id = \strtolower((string) $callingMethodId);

        $codebase->addReferenceToFunctionLike(
            \strtolower((string) $methodId),
            null,
            $context,
            false,
        );
    }

    /**
     * Record an indirect call without inventing a calling method. The plugin file is a stable,
     * non-analyzed source for this synthetic edge, so it is not removed when an application file
     * is re-analyzed during an incremental run. With no context, the graph falls back to this
     * file as the reference's source node.
     *
     * @psalm-external-mutation-free
     */
    public static function recordFileReference(Codebase $codebase, MethodIdentifier $methodId): void
    {
        if ($codebase->find_unused_code === null) {
            return;
        }

        $codebase->addReferenceToFunctionLike(
            \strtolower((string) $methodId),
            null,
            null,
            true,
            __FILE__,
        );
    }
}
