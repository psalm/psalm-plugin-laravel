---
title: Debugging with Xdebug
parent: Contributing
nav_order: 2
---

# Debugging with Xdebug

To debug plugin code with Xdebug and PhpStorm:

1. [Configure PhpStorm for CLI debugging](https://www.jetbrains.com/help/phpstorm/debugging-a-php-cli-script.html)
2. Run Psalm with Xdebug enabled:

```shell
XDEBUG_MODE=debug XDEBUG_SESSION=1 PSALM_ALLOW_XDEBUG=1 vendor/bin/psalm --threads=1 --no-cache
```

`--threads=1` is required so Psalm runs in a single process (breakpoints don't work in forked workers).
`PSALM_ALLOW_XDEBUG=1` prevents Psalm from restarting itself without Xdebug (it does this by default for performance).
