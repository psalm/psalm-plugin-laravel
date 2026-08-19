--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runArtisanCommandViaFoundationKernel(\Illuminate\Http\Request $request) {
    $command = $request->input('command');
    /** @var \Illuminate\Foundation\Console\Kernel $kernel */
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->call($command);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method runArtisanCommandViaFoundationKernel does not have a return type, expecting void
MixedAssignment on line %d: Unable to determine the type that $command is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Foundation\Console\Kernel::call cannot be mixed, expecting Symfony\Component\Console\Command\Command|string
TaintedShell on line %d: Detected tainted shell code
