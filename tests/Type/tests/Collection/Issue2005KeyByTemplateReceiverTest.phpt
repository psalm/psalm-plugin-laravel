--FILE--
<?php declare(strict_types=1);

use App\Models\CollectionGroupingModel;
use Illuminate\Support\Collection;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/2005 (and #1917).
 *
 * keyBy()/groupBy() by column name rebuild the result as `<class><key, value>`. That only fits a plain class
 * receiver whose own templates are (TKey, TValue): a template-bound receiver lost its template (`@return C`
 * failed). The template is only kept when the result keeps the receiver's shape (#2328): groupBy() always nests
 * collections and keyBy() may change the key type, so `@return C` must still be reported there.
 * A subclass with a different template list is covered by Issue2005KeyByForeignTemplateListKnownLimitation.phpt.
 */

/**
 * @template C of Collection<int, CollectionGroupingModel>
 * @param C $rows
 * @return C
 */
function template_receiver_keeps_its_template(Collection $rows): Collection
{
    return $rows->keyBy('foreign_id');
}

/**
 * @template C of Collection<int, CollectionGroupingModel>
 * @param C $rows
 * @return C
 */
function group_by_nests_so_it_is_not_the_template(Collection $rows): Collection
{
    return $rows->groupBy('foreign_id');
}

/**
 * @template C of Collection<string, CollectionGroupingModel>
 * @param C $rows
 * @return C
 */
function key_by_changing_the_key_is_not_the_template(Collection $rows): Collection
{
    return $rows->keyBy('foreign_id');
}

/** @param Collection<string, CollectionGroupingModel> $c */
function plain_receiver_still_narrows(Collection $c): void
{
    $_keyed = $c->keyBy('foreign_id');
    /** @psalm-check-type-exact $_keyed = Collection<int, CollectionGroupingModel> */
}
?>
--EXPECTF--
InvalidReturnType on line %d: The declared return type 'C:fn-group_by_nests_so_it_is_not_the_template as Illuminate\Support\Collection<int, App\Models\CollectionGroupingModel>' for group_by_nests_so_it_is_not_the_template is incorrect, got 'Illuminate\Support\Collection<int, Illuminate\Support\Collection<int, App\Models\CollectionGroupingModel>&static>&static'
InvalidReturnStatement on line %d: The inferred type 'Illuminate\Support\Collection<int, Illuminate\Support\Collection<int, App\Models\CollectionGroupingModel>&static>&static' does not match the declared return type 'C:fn-group_by_nests_so_it_is_not_the_template as Illuminate\Support\Collection<int, App\Models\CollectionGroupingModel>' for group_by_nests_so_it_is_not_the_template
InvalidReturnType on line %d: The declared return type 'C:fn-key_by_changing_the_key_is_not_the_template as Illuminate\Support\Collection<string, App\Models\CollectionGroupingModel>' for key_by_changing_the_key_is_not_the_template is incorrect, got 'Illuminate\Support\Collection<int, App\Models\CollectionGroupingModel>&static'
InvalidReturnStatement on line %d: The inferred type 'Illuminate\Support\Collection<int, App\Models\CollectionGroupingModel>&static' does not match the declared return type 'C:fn-key_by_changing_the_key_is_not_the_template as Illuminate\Support\Collection<string, App\Models\CollectionGroupingModel>' for key_by_changing_the_key_is_not_the_template
