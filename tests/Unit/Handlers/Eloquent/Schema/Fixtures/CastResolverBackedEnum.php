<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures;

enum CastResolverBackedEnum: string
{
    case Draft = 'draft';
    case Published = 'published';
}
