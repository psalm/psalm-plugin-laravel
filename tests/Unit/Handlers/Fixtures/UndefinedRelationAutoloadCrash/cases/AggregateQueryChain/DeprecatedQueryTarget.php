<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AggregateQueryChain;

// Receiver of a retrieval call: ModelAggregateLoadHandler::isQueryType() checks whether it is a Builder/Relation.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedQueryTarget
{
    public function first(): ?object
    {
        return null;
    }
}
