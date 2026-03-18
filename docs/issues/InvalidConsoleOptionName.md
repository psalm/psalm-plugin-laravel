# InvalidConsoleOptionName

Emitted when `$this->option('name')` references an option that is not defined in the command's signature.

## Why this is a problem

If you request an option that doesn't exist in your command's `$signature`, Laravel will throw a `RuntimeException` at runtime.
This check catches the mismatch during static analysis.

## Examples

```php
// Bad — 'verbose-output' is not defined in the signature
class ExportCommand extends Command
{
    protected $signature = 'export {file} {--format=csv}';

    public function handle(): void
    {
        $verbose = $this->option('verbose-output'); // InvalidConsoleOptionName
    }
}
```

```php
// Good — option name matches the signature
class ExportCommand extends Command
{
    protected $signature = 'export {file} {--format=csv}';

    public function handle(): void
    {
        $format = $this->option('format');
    }
}
```

## How to fix

1. Check the `$signature` property of your command for the correct option name
2. Either fix the `option()` call to match the signature, or add the missing option to the signature