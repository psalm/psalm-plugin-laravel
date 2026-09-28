--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @template TUnrelated */
trait RequiresTemplateArgument
{
}

class ArticleWithUnrelatedGenericTrait extends Model
{
    use HasFactory;
    use RequiresTemplateArgument;
}
?>
--EXPECTF--
MissingTemplateParam on line %d: ArticleWithUnrelatedGenericTrait has missing template params when extending RequiresTemplateArgument, expecting 1
