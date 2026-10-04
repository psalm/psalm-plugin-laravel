# PHPT tests

These tests are written using PHPT: syntax, originally created to test PHP interpreter itself.

For the basic usage, please check [alies-dev/psalm-tester](https://github.com/alies-dev/psalm-tester).

To go deeper with PHPT syntax, please check [PHPT](https://qa.php.net/phpt_details.php) official guide.

## Conventions

- When asserting error output, use `--EXPECTF--` (not `--EXPECT--`) with `%d` for line numbers (e.g., `ErrorType on line %d: message`).
  This prevents tests from breaking when lines shift due to unrelated edits.
- A test that needs taint analysis or a non-default config declares an `--ARGS--` section before `--FILE--`,
  e.g. `--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis`, or one of the alternate
  `tests/Type/psalm-*.xml` configs. Without it the test runs under plain defaults, where a negative
  (empty-`--EXPECTF--`) taint or opt-in-rule test passes vacuously.

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
   `--EXPECTF--` block (empty means "no Psalm output at all"). `IssueFormatter::format()` produces no
   preamble or trailer — the whole matched string is issue lines, nothing else — so a `%A` leading an
   issue line only ever absorbs unasserted findings, never structural noise. `bin/ci/check-phpt-conventions.sh`
   rejects this (allowlist: `bin/ci/phpt-leading-wildcard-allowlist.txt`, for provably version-variable
   output only); enumerate every emitted line instead (#1359).

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

6. **`*KnownLimitation.phpt` and `--XFAIL--` cover opposite things; do not swap them.**

   A `*KnownLimitation.phpt` fixture pins the current, known-wrong output: its `--EXPECTF--` asserts
   what Psalm actually emits today (often under-reporting, sometimes nothing), and its docblock points
   at the source class's caveats section explaining why the gap is accepted rather than fixed. It
   passes every run, by design, and only a future code change that closes the gap will turn it red,
   which is the signal to rename the fixture and rewrite it as positive coverage
   (`StructuredArrayElementRead.phpt` is the worked example of that promotion).

   `--XFAIL--` is for a fixture whose `--EXPECTF--` already encodes the desired, not-yet-implemented
   output. It reports incomplete while the behavior is missing (not a failure) and turns into a hard
   failure the moment the fixture starts matching (XPASS), which is the prompt to delete the
   `--XFAIL--` section and let the test pass normally. Nothing in this suite uses it yet; reach for it
   when a fixture is written ahead of the handler or stub that will satisfy it, not when documenting a
   gap nobody is planning to close.

7. **`--CONFLICTS--` key choice controls concurrency, not just isolation.** A fixture with any
   `--CONFLICTS--` key already gets its own Psalm run (psalm-tester groups by the key, and a
   conflicting fixture is never folded into a shared run). The key text then decides whether that
   run can overlap other runs: `all` is a global lock, it waits for every other run to finish before
   starting and blocks every other run from starting while it is live, so several `all` fixtures
   serialize against the whole suite one at a time. A unique key (for example
   `<topic>-<FixtureName>`) gives the fixture its own run without that lock: it starts as soon as
   a concurrency slot is free and runs alongside everything else. Use a unique key when a fixture
   only needs to avoid being co-analyzed with other fixtures (the common case, for example a finding
   that a shared batch drops); reserve `all` for a fixture that must not run at the same time as
   anything else in the suite, which should be rare. Picking `all` by habit serializes the whole
   suite behind those runs for no benefit.
