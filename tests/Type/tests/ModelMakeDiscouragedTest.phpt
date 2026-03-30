--FILE--
<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Article extends Model {}

/** Model with a custom make() method — should NOT be flagged */
class CustomMakeModel extends Model {
    /** @param array<string, mixed> $attributes */
    public static function make(array $attributes = []): self
    {
        // Custom factory logic
        return new self($attributes);
    }
}

/** Model inheriting a custom make() from a non-base Model subclass — should NOT be flagged */
class ChildOfCustom extends CustomMakeModel {}

trait HasCustomMake {
    /** @param array<string, mixed> $attributes */
    public static function make(array $attributes = []): self
    {
        /** @var self */
        return new self($attributes);
    }
}

/** Model using a trait that provides make() — should NOT be flagged */
class TraitMakeModel extends Model {
    use HasCustomMake;
}

function model_make_is_discouraged(): void
{
    // Should emit ModelMakeDiscouraged — use new Article() instead
    Article::make(['title' => 'Hello']);

    // No arguments — should still emit
    Article::make();

    // Case-insensitive — PHP method names are case-insensitive
    Article::Make(['status' => 'draft']);

    // Base Model class should also be flagged
    Model::make(['key' => 'value']);

    // Non-Model classes must NOT trigger the issue
    Collection::make([1, 2, 3]);

    // Custom make() method on model — should NOT trigger the issue
    CustomMakeModel::make(['title' => 'Hello']);

    // Inherited custom make() — should NOT trigger the issue
    ChildOfCustom::make(['title' => 'Hello']);

    // Trait-provided make() — should NOT trigger the issue
    TraitMakeModel::make(['title' => 'Hello']);
}
?>
--EXPECTF--
ModelMakeDiscouraged on line %d: Use new Article(...) instead of Article::make(...). The constructor is clearer and avoids magic method indirection.
ModelMakeDiscouraged on line %d: Use new Article(...) instead of Article::make(...). The constructor is clearer and avoids magic method indirection.
ModelMakeDiscouraged on line %d: Use new Article(...) instead of Article::make(...). The constructor is clearer and avoids magic method indirection.
ModelMakeDiscouraged on line %d: Use new Model(...) instead of Model::make(...). The constructor is clearer and avoids magic method indirection.
