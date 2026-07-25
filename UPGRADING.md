# Upgrade guide

The `1.x` and `2.x` lines are end-of-life (no more fixes, security or otherwise).
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

## 1.x → 2.x

No breaking API changes, the jump is in platform minimums. Bump the constraint:

```json
"require-dev": {
  "psalm/plugin-laravel": "^2.0"
}
```

```bash
composer update psalm/plugin-laravel --with-dependencies
```

Project minimums: PHP `^8.0`, Laravel 8/9, Psalm 4. Laravel 6 and 7 are no longer supported.

Since 2.x is end-of-life, treat this as a stepping stone: unless you are pinned to Laravel 8 or 9, carry on to [3.x](#2x--3x) and 4.x in the same sitting.

Full diff: https://github.com/psalm/psalm-plugin-laravel/compare/v1.6.3...v2.0.0

## Clear the cache after upgrading

After bumping the plugin version, clear Psalm's cache so analysis runs against the new stubs and handlers:

```bash
vendor/bin/psalm --clear-cache
```

A cache carried over from the previous plugin version can surface false positives (most visibly `UndefinedMagicMethod` on Eloquent scopes and relations), because the cached per file results still reflect the old type data. This matters most when tracking `dev-master` or a release candidate, where the plugin changes between runs. Clearing the cache resolves these stale findings.

## Stuck?

Open an issue: https://github.com/psalm/psalm-plugin-laravel/issues
