<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

/**
 * Primary-key storage type — reflects `Model::getKeyType()` after trait overrides
 * (HasUuids / HasUlids force string).
 *
 * @internal
 */
enum PrimaryKeyType: string
{
    case Integer = 'int';
    case String = 'string';
}
