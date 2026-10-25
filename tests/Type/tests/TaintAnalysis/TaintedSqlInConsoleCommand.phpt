--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/** @psalm-taint-source input */
function userInput(): string
{
    return '';
}

final class PurgeCommand extends \Illuminate\Console\Command
{
    public function handle(): void
    {
        $name = userInput();

        echo $name;
        \Illuminate\Support\Facades\DB::statement('DELETE FROM users WHERE name = ' . $name);
    }
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
