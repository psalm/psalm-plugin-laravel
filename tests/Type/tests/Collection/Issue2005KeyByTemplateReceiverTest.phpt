--FILE--
<?php declare(strict_types=1);

use App\Models\CollectionGroupingModel;
use Illuminate\Support\Collection;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/2005 (and #1917).
 *
 * keyBy()/groupBy() by column name rebuild the result as `<class><key, value>`. That only fits a plain class
 * receiver whose own templates are (TKey, TValue): a template-bound receiver lost its template (`@return C`
 * failed), and a subclass with a different template list got the wrong slot rewritten.
 */

/**
 * @template V
 * @template M
 * @extends Collection<int, V>
 */
final class Issue2005WithMeta extends Collection {}

/**
 * @template C of Collection<int, CollectionGroupingModel>
 * @param C $rows
 * @return C
 */
function template_receiver_keeps_its_template(Collection $rows): Collection
{
    return $rows->keyBy('foreign_id');
}

/** @param Issue2005WithMeta<CollectionGroupingModel, string> $c */
function different_template_list_is_not_rewritten(Issue2005WithMeta $c): void
{
    $_keyed = $c->keyBy('foreign_id');
    /** @psalm-check-type-exact $_keyed = Issue2005WithMeta<array-key, CollectionGroupingModel> */
}

/** @param Collection<string, CollectionGroupingModel> $c */
function plain_receiver_still_narrows(Collection $c): void
{
    $_keyed = $c->keyBy('foreign_id');
    /** @psalm-check-type-exact $_keyed = Collection<int, CollectionGroupingModel> */
}
?>
--EXPECTF--
