# PHPT tests

These tests are written using PHPT: syntax, originally created to test PHP interpreter itself.

For the basic usage, please check [phpyh/psalm-tester](https://github.com/phpyh/psalm-tester).

To go deeper with PHPT syntax, please check [PHPT](https://qa.php.net/phpt_details.php) official guide.

## Conventions

- When asserting error output, use `--EXPECTF--` (not `--EXPECT--`) with `%d` for line numbers (e.g., `ErrorType on line %d: message`).
  This prevents tests from breaking when lines shift due to unrelated edits.

### `--EXPECTF--` format specifiers

| Pattern | Matches                                                      |
|---------|--------------------------------------------------------------|
| `%d`    | One or more digits (`[0-9]+`)                                |
| `%s`    | One or more characters (`.+`)                                |
| `%S`    | Zero or more characters (`.*`) — optional match              |
| `%i`    | Signed integer (`[+-]?\d+`)                                  |
| `%f`    | Floating point number (`[+-]?\.?\d+\.?\d*(?:[Ee][+-]?\d+)?`) |
| `%c`    | Single character (`.`)                                       |
| `%x`    | Hex digits (`[0-9a-fA-F]+`)                                  |
| `%e`    | Directory separator (`\\` or `/`)                            |
| `%%`    | Literal `%`                                                  |
