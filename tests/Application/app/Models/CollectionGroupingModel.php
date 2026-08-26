<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int                       $foreign_id
 * @property bool                      $active
 * @property CollectionGroupingIntEnum $kind
 * @property int|null                  $nullable_id
 */
final class CollectionGroupingModel extends Model {}
