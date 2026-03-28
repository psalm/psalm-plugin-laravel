--FILE--
<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Post extends Model {}

function test(): void
{
    // Should emit ModelMakeDiscouraged — use new Post() instead
    Post::make(['title' => 'Hello']);

    // No arguments — should still emit
    Post::make();

    // Non-Model classes must NOT trigger the issue
    Collection::make([1, 2, 3]);
}
?>
--EXPECTF--
ModelMakeDiscouraged on line %d: Use new Post() instead of Post::make(). The constructor is clearer and avoids __callStatic indirection.
ModelMakeDiscouraged on line %d: Use new Post() instead of Post::make(). The constructor is clearer and avoids __callStatic indirection.
