--FILE--
<?php declare(strict_types=1);

use App\Models\CollectionGroupingModel;
use Illuminate\Support\Collection;

/**
 * @template V
 * @template M
 * @extends Collection<int, V>
 */
final class Issue2005WithMeta extends Collection {}

/**
 * Known limitation (see the keepsBaseTemplateLayout() docblock in CollectionGroupByKeyByHandler): for a subclass
 * whose own template list is not `<TKey, TValue>`, the handler declines and Psalm resolves the stub's
 * `static<array-key, TValue>` return positionally against the subclass's own templates. The `array-key` lands on
 * V (the value slot here) and the model on M, so the pinned type is wrong: the right answer would be
 * `Issue2005WithMeta<CollectionGroupingModel, string>`. Rewriting the slots ourselves is not possible without
 * mapping the subclass's templates back to the base's TKey/TValue, and declining is the conservative choice (#1917).
 * The 4.x handler produced the same type before #2005, so this is not a regression of the decline.
 */
/** @param Issue2005WithMeta<CollectionGroupingModel, string> $c */
function different_template_list_is_not_rewritten(Issue2005WithMeta $c): void
{
    $_keyed = $c->keyBy('foreign_id');
    /** @psalm-check-type-exact $_keyed = Issue2005WithMeta<array-key, CollectionGroupingModel> */
}
?>
--EXPECTF--
