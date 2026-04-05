<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\ServiceRecordBuilder;
use App\Collections\ServiceRecordCollection;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Model with custom builder and collection that have no @template parameters.
 *
 * Tests the builderType() and collectionType() branches that return plain TNamedObject
 * instead of TGenericObject, avoiding TooManyTemplateParams.
 *
 * @see ServiceRecordBuilder — extends Builder<ServiceRecord> without declaring its own @template.
 * @see ServiceRecordCollection — extends Collection<int, ServiceRecord> without declaring its own @template.
 */
#[UseEloquentBuilder(ServiceRecordBuilder::class)]
#[CollectedBy(ServiceRecordCollection::class)]
final class ServiceRecord extends Model
{
    protected $table = 'service_records';
}
