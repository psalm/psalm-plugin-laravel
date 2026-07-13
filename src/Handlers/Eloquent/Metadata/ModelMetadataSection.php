<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

/**
 * Independently-computed parts of a model metadata snapshot.
 *
 * Kept free of Psalm-version-specific types so the registry isolation design can be
 * backported to the Psalm 6-based 3.x plugin line.
 *
 * @internal
 */
enum ModelMetadataSection: string
{
    case StorageMethods = 'storage-methods';
    case Relations = 'relations';
    case Reflection = 'reflection';
    case ModelInstance = 'model-instance';
    case RuntimeConfiguration = 'runtime-configuration';
    case Schema = 'schema';
    case Casts = 'casts';
    case PrimaryKey = 'primary-key';
    case CustomTypes = 'custom-types';
}
