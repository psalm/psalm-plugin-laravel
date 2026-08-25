---
title: UnknownFilesystemDisk
parent: Custom Issues
nav_order: 12
---

# UnknownFilesystemDisk

Emitted when `Storage::disk()` / `Storage::drive()` (or the same call on a DI-injected `FilesystemManager` or `Factory` contract) is given a literal disk name that is not a key in `filesystems.disks`.

## Why this is a problem

An unconfigured disk name is not a silent fallback to the `local` disk. `FilesystemManager::resolve()` throws a hard `InvalidArgumentException` at runtime, so the failure mode is availability (the request or job dies), not a wrong write target.

## Examples

```php
// filesystems.disks configures: local, public, s3

// Bad — typo, no 's3-old' disk configured
Storage::disk('s3-old')->put('file.txt', $contents); // UnknownFilesystemDisk

// Good
Storage::disk('s3')->put('file.txt', $contents);
```

## How to fix

1. Check the disk name against `config/filesystems.php`'s `disks` array
2. Fix the typo, or add the missing disk to `filesystems.disks`

## Configuration

This check is disabled by default. Enable it in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <findUnknownFilesystemDisks value="true" />
    </pluginClass>
</plugins>
```

## Limitations

- Only an unconcatenated string literal disk name is checked. `Storage::disk('a' . 'b')`, `Storage::disk(SOME_CONST)`, dynamic, enum, and `null` names are all skipped
- An empty string literal (`Storage::disk('')`) is skipped — Laravel resolves it to the default disk, not a lookup failure
- The global `\Storage` root alias is not covered; use the `Storage` facade or an injected `FilesystemManager`/`Factory`
- Disabled when the project is analyzed under the Testbench package-mode fallback (no `bootstrap/app.php` resolved) — that boot reads Testbench's own bundled config, not the analyzed project's
- A disk registered at runtime is not visible to this check and is reported anyway. `Storage::fake('avatars')` and `FilesystemManager::set($name, $disk)` write into `$disks[$name]`, which `FilesystemManager::get()` reads before falling through to `resolve()`. So `Storage::fake('avatars'); Storage::disk('avatars');` in a test reports even though the code is correct. Add the disk to `config/filesystems.disks`, or suppress the issue in that file
