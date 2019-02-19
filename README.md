# laravel-psalm-plugin
A Psalm plugin for Laravel

## Installation

```
composer require --dev psalm/plugin-laravel
vendor/bin/psalm-plugin enable psalm/plugin-laravel
```

## How it works

Under the hood it just runs https://github.com/barryvdh/laravel-ide-helper and feeds the resultant stubs into Psalm. It also uses a couple of function return type providers, but nothing special.

