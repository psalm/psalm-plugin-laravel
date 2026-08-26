<?php

declare(strict_types=1);

namespace App\Collections;

use App\Models\CollectionGroupingModel;
use Illuminate\Database\Eloquent\Collection;

/** @extends Collection<int, CollectionGroupingModel> */
final class CollectionGroupingCollection extends Collection {}
