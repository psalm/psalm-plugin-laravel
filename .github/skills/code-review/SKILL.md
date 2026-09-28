---
name: code-review
description: Review pull requests in psalm-plugin-laravel (the Psalm plugin for Laravel). Use when reviewing any PR or diff that touches stubs/ (.phpstub), src/Handlers/, src/Issues/, tests/Type/ (.phpt), taint annotations (@psalm-taint-*), or composer.json version constraints. Adds the review-only checks that the repository guide does not spell out, and the list of things not to flag.
---

# Code review for psalm-plugin-laravel

The repository guide already loaded for this review holds the hard rules and code patterns; apply them as written. This file adds only what a reviewer needs on top.

This branch is `3.x`: Psalm 6 (`vimeo/psalm ^6.16`), Laravel `^11.35 || ^12.14 || ^13.3`. It receives backports from `4.x` (Psalm 7).

## Procedure

1. Classify every changed file: stub, handler, issue class, type test, unit test, taint annotation, docs, CI.
2. Verify every claim about Laravel or Psalm behavior against `vendor/laravel/framework/` or `vendor/vimeo/psalm/` source, never against memory or Laravel's own PHPDoc.
3. Report a finding only when you can name the concrete input that breaks and the smallest fix.

## Stubs

- A method typed in `stubs/common/` must exist with that signature on every supported Laravel major (11, 12, 13). Newer-only behavior belongs in `stubs/<version>/`.
- A `@param` narrower than what Laravel accepts causes `ArgumentTypeCoercion` false positives. Compare against the Laravel source signature.
- Never `@psalm-seal-methods` on Model, Builder, Relation, or Collection: it blocks `__call` re-dispatch.

## Handlers

- Handlers depend on Psalm APIs and Laravel knowledge, not on each other. Flag a handler importing another handler.
- Flag static state on `ClassLikeStorage::$custom_metadata` holding non-scalar values: it does not survive forked workers.

## Taint annotations

- Annotations added to an underlying class (for example `Connection`) must also appear on the facade stub (`DB`), and vice versa.
- Generated alias stubs (`aliases.phpstub`, bare `class X extends Y {}`) carry no taint annotations; a fix that relies on them is incomplete.
- A full-class taint stub for a class reached only through a return-type-provider narrowing (`auth('web')`, `app('encrypter')`) becomes the sole class definition and strips every non-stubbed method. That case needs a scan-phase handler on the real `MethodStorage`.

## Type tests

- `@psalm-check-type-exact` fires only as an inline statement-attached docblock. It is dead inside a method or class docblock.
- Namespaced phpt code needs a leading backslash on class names, and every class used by `check-type-exact` must be imported.
- `--EXPECTF--` with `%A` is a lower bound and cannot assert an exact count or absence. Negatives need a separate test with an empty `--EXPECT--`.
- Reuse the model archetypes in `tests/Application/app/Models/` before declaring inline models; inline models break scope calls at `errorLevel=1`.

## Error handling

- Failed stub generation, Laravel app boot, or model and facade discovery must produce a visible warning through `src/Internal/InternalErrorReporter.php`, never silent degradation.
- Flag `catch (\Throwable)` or `catch (\Exception)` broader than the failure mode it guards.

## Do not flag

- Formatting, import order, spacing, and other output of `composer cs` or `composer rector`.
- Unit test method names that are not camelCase.
- `@psalm-` prefixed docblocks that duplicate a `@var`: Rector strips the plain form, the prefixed form is intentional.
- Anything already reported by CI (Psalm self-analysis, PHPUnit, actionlint, spelling).
