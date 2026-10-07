--FILE--
<?php declare(strict_types=1);

use App\Models\Concerns\Reportable;
use App\Models\DamageReport;
use App\Models\GenericBox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * https://github.com/psalm/psalm-plugin-laravel/issues/1753
 *
 * DamageReport::subject() declares `@return MorphTo<Model&Reportable, self>` on a registered model.
 * The external call keeps the declared intersection as TRelatedModel; the declaring slot is the receiver.
 */

function issue1753_call_keeps_intersection(DamageReport $report): MorphTo
{
    $relation = $report->subject();
    /** @psalm-check-type-exact $relation = MorphTo<Model&Reportable, DamageReport> */
    return $relation;
}

function issue1753_get_related_keeps_intersection(DamageReport $report): Model
{
    $related = $report->subject()->getRelated();
    /** @psalm-check-type-exact $related = Model&Reportable */
    return $related;
}

function issue1753_property_keeps_intersection(DamageReport $report): ?Model
{
    $subject = $report->subject;
    /** @psalm-check-type-exact $subject = Model&Reportable|null */
    return $subject;
}

// A nested `static` cannot be bound by a provider result (it skips Psalm's expansion), so the call
// declines and the declared return applies, bound by Psalm.
function issue1753_nested_static_declines(DamageReport $report): MorphTo
{
    $relation = $report->boxed();
    /** @psalm-check-type-exact $relation = MorphTo<GenericBox<DamageReport&static>, DamageReport> */
    return $relation;
}

?>
--EXPECTF--
