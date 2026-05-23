# Upgrade guide

The `2.x` line is end-of-life (no more fixes, security or otherwise).
Active development happens on `3.x` and `4.x`, which add taint-based security scanning and modern Laravel/Psalm support.

Compatibility matrix: [README](https://github.com/psalm/psalm-plugin-laravel/blob/master/README.md#versions--dependencies).

## 3.x → 4.x

4.x requires Laravel 12 or 13, and Psalm 7. Upgrade Psalm first (3.x ships on Psalm 6), then bump the constraint:

```json
"require-dev": {
  "psalm/plugin-laravel": "^4.0"
}
```

```bash
composer update psalm/plugin-laravel --with-dependencies
```

Eloquent relation generics changed shape. See [`docs/upgrade-v4.md`](https://github.com/psalm/psalm-plugin-laravel/blob/master/docs/upgrade-v4.md) for the full migration, including a Psalter codemod for annotations.

## 2.x → 3.x

No breaking API changes. Bump the constraint:

```json
"require-dev": {
  "psalm/plugin-laravel": "^3.0"
}
```

```bash
composer update psalm/plugin-laravel --with-dependencies
```

Project minimums: PHP `^8.2`, Laravel 11/12/13, Psalm 6.

Full diff: https://github.com/psalm/psalm-plugin-laravel/compare/v2.11.1...v3.0.0

## Stuck?

Open an issue: https://github.com/psalm/psalm-plugin-laravel/issues
