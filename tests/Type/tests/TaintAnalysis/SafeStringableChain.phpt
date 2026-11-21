--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Str;

/**
 * Each Stringable keeps its own taint: a tainted upper() call doesn't taint another one. A result that can't hold
 * the string's characters drops the taint, like base64_encode(), and slug() and stripTags() remove HTML.
 */
function showSafe(\Illuminate\Http\Request $request): void {
    $name = $request->str('name');
    $_tainted = $name->upper();

    echo Str::of('safe')->upper();
    echo $name->hash('sha256');
    echo $name->toBase64();
    echo $name->numbers();
    echo $name->length();
    echo $name->slug();
    echo '<p>' . $name->stripTags()->value() . '</p>';
}
?>
--EXPECTF--
