--FILE--
<?php declare(strict_types=1);

use App\Models\Concerns\Reportable;
use App\Models\DamageReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * https://github.com/psalm/psalm-plugin-laravel/issues/1753
 *
 * DamageReport::subject() declares `@return MorphTo<Model&Reportable, self>` on a registered model.
 * The call-site handler declines morphTo, so Psalm applies the declared return: the intersection
 * stays TRelatedModel and the declaring slot is the receiver.
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

?>
--EXPECTF--
