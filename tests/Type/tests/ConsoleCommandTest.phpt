--FILE--
<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExampleCommand extends Command
{
    /** @var string */
    protected $signature = 'example:run
        {email : The user email}
        {role? : Optional role}
        {name=World : Optional arg with default}
        {tags?* : Tags array}
        {--F|force : Force flag}
        {--limit= : Value-accepting option}
        {--format=json : Value-accepting option with default}
        {--ids=* : Array option}
    ';

    public function handle(): int
    {
        // Required scalar argument → string
        $_email = $this->argument('email');
        /** @psalm-check-type-exact $_email = string */

        // Optional scalar argument without default → string|null
        $_role = $this->argument('role');
        /** @psalm-check-type-exact $_role = null|string */

        // Optional scalar argument with default → string (never null)
        $_name = $this->argument('name');
        /** @psalm-check-type-exact $_name = string */

        // Array argument → array<int, string>
        $_tags = $this->argument('tags');
        /** @psalm-check-type-exact $_tags = array<int, string> */

        // No-value flag → bool
        $_force = $this->option('force');
        /** @psalm-check-type-exact $_force = bool */

        // Value-accepting option → string|null
        $_limit = $this->option('limit');
        /** @psalm-check-type-exact $_limit = null|string */

        // Value-accepting option with default → string|null
        $_format = $this->option('format');
        /** @psalm-check-type-exact $_format = null|string */

        // Array option → array<int, string>
        $_ids = $this->option('ids');
        /** @psalm-check-type-exact $_ids = array<int, string> */

        // Inherited global option (--verbose) → bool
        $_verbose = $this->option('verbose');
        /** @psalm-check-type-exact $_verbose = bool */

        // Inherited global option (--env) → string|null
        $_env = $this->option('env');
        /** @psalm-check-type-exact $_env = null|string */

        // Inherited negatable global option (--ansi/--no-ansi) → bool|null
        $_ansi = $this->option('ansi');
        /** @psalm-check-type-exact $_ansi = bool|null */

        return 0;
    }
}
?>
--EXPECT--
