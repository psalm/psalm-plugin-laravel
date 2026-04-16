<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Discriminator describing how a cast was declared on a model.
 *
 * Consumers pattern-match on this to drive {@see \Psalm\Type\Union} construction —
 * e.g. `BackedEnum` needs the target enum's FQCN while `Primitive` does not.
 *
 * @internal
 */
enum CastShape: string
{
    /** int, bool, string, float, array, object, collection */
    case Primitive = 'primitive';

    /** date, datetime, immutable_date, immutable_datetime */
    case DateTime = 'datetime';

    /** Cast target is a BackedEnum subclass */
    case BackedEnum = 'backed_enum';

    /** `AsEnumCollection::of(Status::class)` */
    case AsEnumCollection = 'as_enum_collection';

    case AsArrayObject = 'as_array_object';
    case AsCollection = 'as_collection';
    case AsStringable = 'as_stringable';
    case AsEncrypted = 'as_encrypted';

    /** User class implementing CastsAttributes */
    case CustomCastsAttributes = 'custom';
}
