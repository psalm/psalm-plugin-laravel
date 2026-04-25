# psalm.xml Template for Audited Laravel Apps

Adapt this template to the target project's directory structure.
Check which directories and files exist before including them.

```xml
<?xml version="1.0"?>
<psalm
    errorLevel="3"
    resolveFromConfigFile="true"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="https://getpsalm.org/schema/config"
    xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
    errorBaseline="psalm-baseline.xml"
    findUnusedBaselineEntry="true"
    findUnusedCode="false"
    findUnusedPsalmSuppress="true"
>
    <projectFiles>
        <directory name="app" />
        <directory name="bootstrap" />
        <directory name="config" />
        <directory name="database" />
        <directory name="routes" />
        <file name="artisan" />
        <file name="public/index.php" />
        <ignoreFiles>
            <directory name="bootstrap/cache" />
            <directory name="vendor" />
        </ignoreFiles>
    </projectFiles>

    <plugins>
        <pluginClass class="Psalm\LaravelPlugin\Plugin">
            <failOnInternalError>true</failOnInternalError>
        </pluginClass>
    </plugins>

    <!-- Suppress noisy issues that aren't actionable in a typical Laravel codebase.
         These can be tightened later as the team fixes issues incrementally. -->
    <issueHandlers>
        <!-- Laravel models rarely set all properties in constructors -->
        <PropertyNotSetInConstructor errorLevel="info" />
        <!-- Deprecated usage is informational, not blocking -->
        <DeprecatedMethod errorLevel="info" />
        <DeprecatedClass errorLevel="info" />
        <!-- Override attribute and purity annotations are too noisy for most Laravel codebases -->
        <MissingOverrideAttribute errorLevel="info" />
        <MissingPureAnnotation errorLevel="info" />
        <MissingAbstractPureAnnotation errorLevel="info" />
        <MissingInterfaceImmutableAnnotation errorLevel="info" />
        <MissingImmutableAnnotation errorLevel="info" />
    </issueHandlers>
</psalm>
```

## Adaptation Notes

- **Directories**: only include directories that exist in the project
- **Entry files**: check if the project uses `artisan` (Laravel 11+) or `artisan.php` (older/custom)
  and include whichever exists. Some projects have neither as an analyzed file — that's fine.
- **Custom structure**: if the project has `src/` instead of `app/`, adjust accordingly.
  Check `composer.json` autoload for the PSR-4 mapping.
- **Extra directories**: if the project has `lang/` or `tests/`, consider including them
- **Error level**: `errorLevel="3"` is the default. Use `errorLevel="1"` if PHPStan is at max level.
- **Baseline**: the baseline file ensures the PR passes CI even with existing issues
- **`findUnusedCode="false"`**: keeps the initial run focused on types and security
- **`bootstrap/cache`**: contains generated files that should not be analyzed

### Audit vs PR config

- **During audit**: keep `<failOnInternalError>true</failOnInternalError>` to surface plugin bugs
- **For the PR**: remove `failOnInternalError` (or set to `false`). If the plugin has an internal
  error, it should not break the target project's CI — bad first impression for an unsolicited PR.