# PHPT tests

These tests are written using PHPT: syntax, originally created to test PHP interpreter itself.

For the basic usage, please check [alies-dev/psalm-tester](https://github.com/alies-dev/psalm-tester).

To go deeper with PHPT syntax, please check [PHPT](https://qa.php.net/phpt_details.php) official guide.

## Conventions

- When asserting error output, use `--EXPECTF--` (not `--EXPECT--`) with `%d` for line numbers (e.g., `ErrorType on line %d: message`).
  This prevents tests from breaking when lines shift due to unrelated edits.

### `--EXPECTF--` format specifiers

| Pattern | Matches                                                      |
|---------|--------------------------------------------------------------|
| `%d`    | One or more digits (`[0-9]+`)                                |
| `%s`    | One or more characters (`.+`)                                |
| `%S`    | Zero or more characters (`.*`), an optional match            |
| `%i`    | Signed integer (`[+-]?\d+`)                                  |
| `%f`    | Floating point number (`[+-]?\.?\d+\.?\d*(?:[Ee][+-]?\d+)?`) |
| `%c`    | Single character (`.`)                                       |
| `%x`    | Hex digits (`[0-9a-fA-F]+`)                                  |
| `%e`    | Directory separator (`\\` or `/`)                            |
| `%%`    | Literal `%`                                                  |

## Gotchas

Hard-won rules. Each of these has silently produced a test that passes while asserting nothing, or a red CI cell.

1. **`@psalm-check-type-exact` works only as an inline, statement-attached docblock.**
   Placed in a method or class docblock it is silently dead: the test passes without asserting anything.
   Attach it to a statement, right above the line that uses the variable:

   ```php
   $user = User::query()->first();
   /** @psalm-check-type-exact $user = App\Models\User|null */
   ```

2. **`%A` in `--EXPECTF--` is a lower bound, not an exact match.** It expands to a lazy `.*` (with `/s`),
   so it absorbs any extra findings. A test using `%A` cannot pin an exact error count and cannot assert
   that something is NOT reported. To assert the absence of errors, write a separate test with an empty
   `--EXPECTF--` block (empty means "no Psalm output at all").

3. **Version-gated stubs need `--SKIPIF--`.** A test asserting a `stubs/<version>/` override fails on
   the lower cells of the CI matrix, where the override does not load. Gate it:

   ```
   --SKIPIF--
   <?php
   require getcwd() . '/vendor/autoload.php';
   \Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.42.0');
   --FILE--
   ```

   `LaravelVersion::skipFrom()` covers the reverse (behavior only on older lines); `CarbonVersion`
   does the same for Carbon-gated stubs. The `--SKIPIF--` script runs in a bare process from the
   project root, hence the `getcwd()` autoload require.

4. **Reuse the shared app models, do not declare models inline.** Under the strict type-test config
   (`errorLevel=1`), scope calls on a model declared inside the `.phpt` misbehave (repeated scope
   calls degrade to `MixedAssignment`/`MixedMethodCall`/`UndefinedMagicMethod`), and inline public
   scopes or accessors trip the `PublicModelScope`/`PublicModelAccessor` rules. Models under
   `tests/Application/app/Models/` are fully registered archetypes; reuse them
   (`User` for a standard int PK, `UuidModel`, `UlidModel`, `CustomPkUuidModel`, and friends)
   before creating a new one. New models represent PK/trait archetypes, not individual test cases.

5. **`findUnusedCode` assertions cannot be tested here.** The psalm-tester runner passes the file as
   a CLI argument, which makes Psalm skip whole-program analysis, so dead-code issues never fire in a
   `.phpt`. Use a subprocess test running Psalm over a fixture project with `<projectFiles>` instead
   (see `tests/Unit/` fixtures for the pattern). The same limit applies to any rule that emits only
   during a full-project scan; cover those with unit tests plus `composer test:app`.
