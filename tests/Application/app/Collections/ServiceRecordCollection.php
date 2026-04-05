<?php

declare(strict_types=1);

namespace App\Collections;

use App\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Collection;

/**
 * Custom collection without its own @template parameters.
 *
 * Extends Collection with concrete types instead of declaring @template TKey/@template TModel.
 * Tests that the plugin returns a plain TNamedObject (ServiceRecordCollection) instead of
 * TGenericObject to avoid TooManyTemplateParams errors.
 *
 * @extends Collection<int, ServiceRecord>
 */
final class ServiceRecordCollection extends Collection {}
