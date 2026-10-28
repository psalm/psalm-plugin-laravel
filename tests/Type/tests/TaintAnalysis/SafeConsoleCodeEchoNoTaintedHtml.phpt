--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/** @psalm-taint-source input */
function userInput(): string
{
    return '';
}

final class ImportCommand extends \Illuminate\Console\Command
{
    public function handle(): void
    {
        try {
            $this->import();
        } catch (\Throwable $e) {
            echo $e->getMessage();
        }

        print userInput();

        (function (): void {
            echo userInput();
        })();
    }

    private function import(): void {}
}

abstract class BaseCommand extends \Illuminate\Console\Command {}

final class ChildCommand extends BaseCommand
{
    public function handle(): void
    {
        echo userInput();
    }
}

final class CreateUsersTable extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        echo userInput();
    }
}

return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        echo userInput();
    }
};

final class UserSeeder extends \Illuminate\Database\Seeder
{
    public function run(): void
    {
        echo userInput();
    }
}
?>
--EXPECTF--
