# `psalm.xml` template for package audits

Starting point. Adapt `<projectFiles>` to the package's actual layout (read `composer.json` `autoload.psr-4`). Wildcards are not supported in `<directory name="...">` — expand them.

```xml
<?xml version="1.0"?>
<psalm
    errorLevel="1"
    resolveFromConfigFile="true"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="https://getpsalm.org/schema/config"
    xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
    findUnusedCode="false"
>
    <projectFiles>
        <!-- Replace with the package's source directories. Examples:
             single-package:   <directory name="src" />
             monorepo (Filament, Nova, Spatie multi-packages):
                               <directory name="packages/actions/src" />
                               <directory name="packages/forms/src" />
                               ... -->
        <directory name="src" />
        <ignoreFiles>
            <directory name="vendor" />
            <!-- Mirror the package's own phpstan excludes when unambiguous -->
        </ignoreFiles>
    </projectFiles>

    <plugins>
        <pluginClass class="Psalm\LaravelPlugin\Plugin">
            <failOnInternalError>true</failOnInternalError>
        </pluginClass>
    </plugins>

    <issueHandlers>
        <!-- These are too noisy for a one-shot package audit.
             Leave them on if the user asks specifically about purity or construction. -->
        <PropertyNotSetInConstructor errorLevel="info" />
        <DeprecatedMethod errorLevel="info" />
        <DeprecatedClass errorLevel="info" />
        <MissingOverrideAttribute errorLevel="info" />
        <MissingPureAnnotation errorLevel="info" />
        <MissingAbstractPureAnnotation errorLevel="info" />
        <MissingInterfaceImmutableAnnotation errorLevel="info" />
        <MissingImmutableAnnotation errorLevel="info" />
        <ClassMustBeFinal errorLevel="info" />
    </issueHandlers>
</psalm>
```

## Why `errorLevel="1"`

Plugin-gap investigations need `Mixed*` in scope. At level 3 those are suppressed and the report looks misleadingly clean — the very errors the user came to investigate get hidden. Pair level 1 with the noise suppressions above so the signal-to-noise ratio stays workable.

## `<projectFiles>` notes

- No wildcards in `<directory>` entries. Psalm rejects them with "Could not resolve config path".
- For monorepos, add each package's `src/` explicitly. Reading the monorepo's own `phpstan.neon.dist` `paths:` is usually the fastest way to get a correct list.
- Exclude `tests/`, `resources/`, `database/`, and any `bin/` / `Rector` directories the package excludes from its own static analysis. Test code inflates error counts without telling you anything about the plugin.
- If `composer.json` `autoload.files` includes helpers, you can omit them from `<projectFiles>`; they rarely contain information relevant to the audit.

## Deciding what to include (`autoload` vs `autoload-dev`)

One heuristic before writing `<projectFiles>`: list what the package declares under `autoload.psr-4` vs `autoload-dev.psr-4`. Include the former, exclude the latter.

```bash
jq '.autoload["psr-4"], .["autoload-dev"]["psr-4"]' composer.json
```

- Directories that appear only under `autoload.psr-4` (typically `src/`, `packages/*/src` in monorepos) → add them to `<projectFiles>`.
- Directories under `autoload-dev.psr-4` (typically `tests/`, `database/factories/`, `database/seeders/`) → leave out. If any of those paths live *inside* a directory you included (e.g. `src/Tests/`), add them to `<ignoreFiles>`.

For `spatie/*` packages tests usually live outside `src/` entirely, so `<directory name="src" />` alone is sufficient. For some packages and monorepos they share a root; the `jq` command above is how you tell without guessing.