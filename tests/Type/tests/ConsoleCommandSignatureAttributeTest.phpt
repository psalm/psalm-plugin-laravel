--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.0.0');
--FILE--
<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

// Laravel 13 reads #[Signature] in Command::configureFromAttributes(), and it overrides $signature.
#[Signature('test:attribute
    {email : The user email}
    {role? : Optional role}
    {tags?* : Tags array}
    {--F|force : Force flag}
    {--limit= : Value-accepting option}')]
final class AttributeSignatureCommand extends Command
{
    /** @var string */
    #[\Override]
    protected $signature = 'test:legacy {legacy}';

    public function handle(): int
    {
        $_email = $this->argument('email');
        /** @psalm-check-type-exact $_email = string */

        $_role = $this->argument('role');
        /** @psalm-check-type-exact $_role = null|string */

        $_tags = $this->argument('tags');
        /** @psalm-check-type-exact $_tags = array<int, string> */

        $_force = $this->option('force');
        /** @psalm-check-type-exact $_force = bool */

        $_limit = $this->option('limit');
        /** @psalm-check-type-exact $_limit = null|string */

        // the attribute wins, so the property's argument does not exist
        $this->argument('legacy');

        return 0;
    }
}

#[\Illuminate\Console\Attributes\Signature(signature: 'test:named {--dry-run}', aliases: ['test:alias'])]
final class NamedArgumentSignatureCommand extends Command
{
    public function handle(): int
    {
        $_dryRun = $this->option('dry-run');
        /** @psalm-check-type-exact $_dryRun = bool */

        return 0;
    }
}

#[Signature('test:parent {email}')]
class ParentSignatureCommand extends Command
{
}

// Laravel reflects only the concrete class, so a parent's #[Signature] does not apply here.
final class ChildOfAttributeCommand extends ParentSignatureCommand
{
    public function handle(): int
    {
        $this->argument('not-in-parent-signature');

        return 0;
    }
}
?>
--EXPECTF--
InvalidConsoleArgumentName on line %d: Argument 'legacy' is not defined in AttributeSignatureCommand's signature
