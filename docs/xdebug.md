# xdebug

For debugging Plugin code using xdebug you need to follow these steps:

Step 1: [Setup PHPStorm](https://www.jetbrains.com/help/phpstorm/debugging-a-php-cli-script.html):
```shell
export XDEBUG_MODE=debug XDEBUG_SESSION=1
```

Step 2: [Enable xdebug when run Psalm](https://psalm.dev/docs/running_psalm/plugins/authoring_plugins/):
```shell
PSALM_ALLOW_XDEBUG=1 vendor/bin/psalm --threads=1 --no-cache
```
