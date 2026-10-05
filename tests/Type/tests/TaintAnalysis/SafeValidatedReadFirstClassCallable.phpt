--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Psalm fires the add/remove-taints hook for return expressions and call arguments, including
 * first-class callables (`input(...)`). They carry no key to read and getArgs() throws on them, so
 * the validated-read resolver must decline. Pins that crash guard (expect analysis to complete
 * with no output). https://github.com/psalm/psalm-plugin-laravel/issues/1657
 */
final class PlainForm
{
    public function input(string $key): string
    {
        return $key;
    }
}

function takeValidatedReader(\Closure $reader): void
{
    unset($reader);
}

function returnedRequestInput(\Illuminate\Http\Request $request): \Closure
{
    return $request->input(...);
}

function passedPlainInput(PlainForm $form): void
{
    takeValidatedReader($form->input(...));
}
?>
--EXPECTF--
