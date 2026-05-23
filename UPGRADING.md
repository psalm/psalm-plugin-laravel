# Upgrade guide

The `2.x` line is end-of-life — no more fixes, security or otherwise.
Active development happens on `3.x` and `4.x`, which add taint-based security scanning and modern Laravel/Psalm support.

Compatibility matrix:
[README](https://github.com/psalm/psalm-plugin-laravel/blob/master/README.md#versions--dependencies).


## 2.x → 3.x

No breaking API changes. Just bump the constraint:

```json
"require-dev": {
  "psalm/plugin-laravel": "^3.0"
}
```

```bash
composer update psalm/plugin-laravel --with-dependencies
```

Make sure your project meets the new minimums: PHP `^8.2`, Laravel 11/12/13,
Psalm 6 or 7.

Full code diff: https://github.com/psalm/psalm-plugin-laravel/compare/v2.11.1...v3.0.0


## 3.x → 4.x

Upgrade to Psalm 7 first (3.x supports both 6 and 7), then bump:

```json
"require-dev": {
  "psalm/plugin-laravel": "^4.0"
}
```

```bash
composer update psalm/plugin-laravel --with-dependencies
```

4.x requires Laravel 12 or 13. For Eloquent relation annotation refactors, see
[`docs/upgrade-v4.md`](https://github.com/psalm/psalm-plugin-laravel/blob/master/docs/upgrade-v4.md).


## Stuck?

Open an issue: https://github.com/psalm/psalm-plugin-laravel/issues
