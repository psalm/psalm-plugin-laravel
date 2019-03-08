# laravel-psalm-plugin
A Psalm plugin for Laravel

## Installation

```
composer require --dev psalm/plugin-laravel
```

Copy `psalm.xml` to the root of your project and then enable the plugin.

```
vendor/bin/psalm-plugin enable psalm/plugin-laravel
```

## Running Psalm

```
vendor/bin/psalm
```


## How it works

Under the hood it just runs https://github.com/barryvdh/laravel-ide-helper and feeds the resultant stubs into Psalm. It also uses a couple of function return type providers, but nothing special.

