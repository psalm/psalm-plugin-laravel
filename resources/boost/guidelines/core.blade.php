## psalm-plugin-laravel

Psalm plugin for Laravel that provides static analysis with built-in security scanning.
Combines deep Laravel type analysis with taint-based vulnerability detection.

### What it does

- **Type analysis**: understands Eloquent models, facades, container resolution, auth guards, collections, and other Laravel magic
- **Security scanning**: tracks user input from source to sink across your entire codebase using Psalm's taint analysis engine, catching SQL injection, XSS, SSRF, shell injection, file traversal, and open redirects

### Running analysis

```bash
# Run full analysis (types + security) — Psalm 7 runs taint analysis by default
./vendor/bin/psalm

# With extra options
./vendor/bin/psalm --no-cache --no-diff
```

No extra flags are needed for security scanning in Psalm 7. Taint analysis runs automatically alongside type checking.

### Configuration

The plugin is configured in `psalm.xml` via the `<plugins>` section:

@verbatim
<code-snippet name="psalm.xml plugin configuration" lang="xml">
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin" />
</plugins>
</code-snippet>
@endverbatim

Use a baseline file to suppress existing issues and focus on new code:

```bash
./vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

### Key conventions

- Psalm uses `@param`, `@return`, and `@var` annotations for type inference — keep them accurate
- Use parameterized queries (`DB::select('... WHERE id = ?', [$id])`) instead of string interpolation to avoid taint violations
- The plugin reads `config/auth.php` to type auth guards — keep your auth config accurate
- Eloquent model properties are inferred from migration files and `$casts` — no manual annotations needed
