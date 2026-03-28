--FILE--
<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Article extends Model {}

function model_make_is_discouraged(): void
{
    // Should emit ModelMakeDiscouraged — use new Article() instead
    Article::make(['title' => 'Hello']);

    // No arguments — should still emit
    Article::make();

    // Base Model class should also be flagged
    Model::make(['key' => 'value']);

    // Non-Model classes must NOT trigger the issue
    Collection::make([1, 2, 3]);
}
?>
--EXPECTF--
ModelMakeDiscouraged on line %d: Use new Article(...) instead of Article::make(...). The constructor is clearer and avoids magic method indirection.
ModelMakeDiscouraged on line %d: Use new Article(...) instead of Article::make(...). The constructor is clearer and avoids magic method indirection.
ModelMakeDiscouraged on line %d: Use new Model(...) instead of Model::make(...). The constructor is clearer and avoids magic method indirection.
