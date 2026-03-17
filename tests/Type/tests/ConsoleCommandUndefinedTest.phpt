--FILE--
<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UndefinedArgCommand extends Command
{
    /** @var string */
    protected $signature = 'test:undefined {email}';

    public function handle(): int
    {
        // This argument is not defined in the signature — should emit InvalidConsoleArgumentName
        $this->argument('nonexistent');

        // This option is not defined in the signature — should emit InvalidConsoleOptionName
        $this->option('nonexistent');

        return 0;
    }
}
?>
--EXPECT--
InvalidConsoleArgumentName on line 16: Argument 'nonexistent' is not defined in UndefinedArgCommand's signature
InvalidConsoleOptionName on line 19: Option 'nonexistent' is not defined in UndefinedArgCommand's signature
