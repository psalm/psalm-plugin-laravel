<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

/** @internal */
enum MetadataSectionState: string
{
    /** The section was evaluated successfully, including when its data is empty. */
    case Complete = 'complete';

    /** The section could not be evaluated because its input was not available. */
    case Unavailable = 'unavailable';

    /** The section was attempted but raised an unexpected failure. */
    case Failed = 'failed';
}
