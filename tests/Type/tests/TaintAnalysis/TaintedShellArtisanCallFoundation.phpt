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
%ATaintedShell on line %d: Detected tainted shell code
