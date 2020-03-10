# Psalm plugin for Laravel

![Type coverage](https://shepherd.dev/github/psalm/laravel-psalm-plugin/coverage.svg)

## Installation

First [install Psalm](https://psalm.dev/quickstart) in your project, making sure to run `--init`, then run the following commands:

```
composer require --dev psalm/plugin-laravel
vendor/bin/psalm-plugin enable psalm/plugin-laravel
```

## How it works

Under the hood it just runs https://github.com/barryvdh/laravel-ide-helper and feeds the resultant stubs into Psalm, which can read PHPStorm meta stubs.

It also parses any database migrations it can find to try to understand property types in your database models.

