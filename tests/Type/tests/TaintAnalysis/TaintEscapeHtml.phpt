--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderEscapedComment(\Illuminate\Http\Request $request): void {
    /** @var string $comment */
    $comment = $request->input('comment');
    echo e($comment);
}
?>
--EXPECTF--
