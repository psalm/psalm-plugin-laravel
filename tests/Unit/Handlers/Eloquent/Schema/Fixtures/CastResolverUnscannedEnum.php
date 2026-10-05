<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures;

/** Autoloadable but given no storage in CastResolverTest: a cast target Psalm never scanned. */
enum CastResolverUnscannedEnum: string
{
    case Open = 'open';
}
