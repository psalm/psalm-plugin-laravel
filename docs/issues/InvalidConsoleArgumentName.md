---
title: InvalidConsoleArgumentName
parent: Custom Issues
nav_order: 2
---

# InvalidConsoleArgumentName

Emitted when `$this->argument('name')` references an argument that is not defined in the command's signature.

## Why this is a problem

If you request an argument that doesn't exist in your command's `$signature`, Laravel will throw a `RuntimeException` at runtime.
This check catches the mismatch during static analysis.

## Examples

```php
// Bad — 'username' is not defined in the signature
class GreetCommand extends Command
{
    protected $signature = 'greet {name}';

    public function handle(): void
    {
        $user = $this->argument('username'); // InvalidConsoleArgumentName
    }
}
```

```php
// Good — argument name matches the signature
class GreetCommand extends Command
{
    protected $signature = 'greet {name}';

    public function handle(): void
    {
        $user = $this->argument('name');
    }
}
```

## How to fix

1. Check the `$signature` property of your command for the correct argument name
2. Either fix the `argument()` call to match the signature, or add the missing argument to the signature
