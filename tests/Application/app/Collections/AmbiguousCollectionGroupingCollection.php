<?php

declare(strict_types=1);

namespace App\Collections;

use App\Models\CollectionGroupingModel;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

/** @extends Collection<int, CollectionGroupingModel|Customer> */
final class AmbiguousCollectionGroupingCollection extends Collection {}
