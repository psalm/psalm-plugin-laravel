---
title: Laravel IDE Helper
nav_order: 9
---

# Laravel IDE Helper

The plugin types facades, the `Eloquent` alias, model builders and relations on its own, so Psalm needs none of the files that [barryvdh/laravel-ide-helper](https://github.com/barryvdh/laravel-ide-helper) generates. Those files are for your IDE. This page lists how each generator mode fits a Psalm setup.

Generated model PHPDocs work as they are from Psalm 7.5, which the plugin requires. Before it, a model's own `@mixin` replaced the `@mixin Builder<static>` the model inherits ([vimeo/psalm#13671](https://github.com/vimeo/psalm/issues/13671)), and every builder call on a model annotated by ide-helper reported `UndefinedMagicMethod`.

## Model PHPDocs (`ide-helper:models`)

| Command | What it writes | Psalm setup |
|---|---|---|
| `php artisan ide-helper:models --write` | PHPDocs in each model file, ending with `@mixin \Eloquent` (`@mixin Eloquent` when the file already imports the alias) | Nothing to add |
| `php artisan ide-helper:models --write-mixin` | `@mixin IdeHelper<Model>` in each model file, and the `IdeHelper<Model>` classes in `_ide_helper_models.php` | Let Psalm scan the generated file (below) |
| `php artisan ide-helper:models --nowrite` | A second declaration of every model in `_ide_helper_models.php`; model files stay untouched | Keep `_ide_helper_models.php` out of Psalm |

Builder methods (`where()`, `find()`, `orderBy()`, your custom builder's methods) resolve on a model with a generated `@mixin` exactly as on a model without one. Do not post-process the generated docblocks. An `@mixin \Illuminate\Database\Eloquent\Builder<static>` line added by hand as a workaround is no longer needed, and keeping it is harmless.

`--reset` removes the existing model PHPDocs instead of appending to them, so use it only when you mean to discard hand-written annotations.

### `--write-mixin`

The model's `@mixin IdeHelper<Model>` points at a class that only exists in the generated models file. Psalm's `<extraFiles>` only reads `<directory>` entries, so give that file a directory of its own in `config/ide-helper.php`:

```php
'models_filename' => 'ide-helper/_ide_helper_models.php',
```

Create the `ide-helper` directory, regenerate, then let Psalm scan it without analyzing it:

```xml
<extraFiles>
    <directory name="ide-helper"/>
</extraFiles>
```

Without it, Psalm does not know the `IdeHelper<Model>` classes, so the `@property` types they document do not reach your models. Builder methods resolve either way.

### `--nowrite`

`_ide_helper_models.php` then declares `class Post extends \Eloquent {}` next to your real `Post`. Do not add it to `<projectFiles>`, `<extraFiles>` or `<stubs>`, or Psalm sees every model declared twice.

### Helper classes with builder method names

Keep builder method names (`first()`, `find()`, `where()`, ...) out of classes you mix into a model. Laravel's runtime forwarding ignores docblock mixins and always sends such a call to the builder, so a helper's own `first()` describes code that never runs.

## Facade helper (`_ide_helper.php`)

Do not register `_ide_helper.php` as a Psalm `<stubs>` file. The plugin already types facades and declares your app's `AliasLoader` aliases, `Eloquent` included. Since Psalm 7.0 rc3 ([vimeo/psalm@9bd7daf](https://github.com/vimeo/psalm/commit/9bd7dafbad9094727e56608dfea9080acc1f4b8a)), many docblocks that the generator writes into that file report `InvalidDocblock` when it is loaded as a stub ([barryvdh/laravel-ide-helper#1659](https://github.com/barryvdh/laravel-ide-helper/issues/1659)).

If a project must keep the file registered, suppress the issue for that file only. This hides the diagnostics; it does not make the docblocks valid:

```xml
<issueHandlers>
    <InvalidDocblock>
        <errorLevel type="suppress">
            <file name="_ide_helper.php"/>
        </errorLevel>
    </InvalidDocblock>
</issueHandlers>
```
