---
title: ImplicitFormRequestPropertyRead
parent: Custom Issues
nav_order: 11
---

# ImplicitFormRequestPropertyRead

Opt-in. Emitted when an undeclared field is read as a magic property on a `FormRequest` subclass (`$this->email`, `$request->email`) instead of through an explicit validated-input accessor.

This issue is **disabled by default**. Enable it with `<reportImplicitFormRequestPropertyReads value="true" />` (see [Configuration](../config.md#reportimplicitformrequestpropertyreads)).

## Why this is a problem

A read like `$this->email` or `$request->email` does not hit a declared property. Laravel resolves it through `Request::__get`, which reads the raw input bag first and only falls back to a route parameter of the same name when the field is absent from input. Two consequences follow.

- The property is pure magic, easy to misread as a real, declared field.
- The read returns **raw** input, bypassing the `validated()` / `safe()` contract even on a validated `FormRequest`, where the intent is almost always the validated value.

Teams that prefer the explicit forms enable this rule to require `validated()`, `safe()`, or `input()`, which make the data source obvious to both readers and tooling. It is the `FormRequest` counterpart of [ImplicitQueryBuilderCall](ImplicitQueryBuilderCall.md), applied to validation input access.

## What is flagged

A magic read whose receiver is a `FormRequest` subclass and whose field has a presence-guaranteeing validation rule (`required`, `present`, `accepted`, `declined`) but no real or `@property` declaration. That is exactly the set of reads the plugin silently narrows to the rule type (see [#1022](https://github.com/psalm/psalm-plugin-laravel/issues/1022)), so the rule and the narrowing agree on which fetches count as magic input reads.

The following are **not** flagged.

- A declared member (a real property, or an `@property` / `@property-read` annotation) opts out, because the field is no longer magic.
- A field with no rule, or a rule that does not guarantee presence (`sometimes`, `nullable`), is left to Psalm's own `UndefinedThisPropertyFetch` / `UndefinedPropertyFetch`. The plugin's `Request` stub omits `__get`, so such a read is already reported, and flagging it here too would double-report.
- A presence-test or removal context. `isset($request->field)` and `unset($request->field)` go through `__isset` (and there is no `__unset`), not a value-returning `__get` read, so they are not magic input reads. `empty($request->field)` also reads through `__get`, but it shares the same analysis context as `isset()` and is deferred too (a minor, deliberate false negative, in favor of zero false positives on `isset()` / `unset()` where the message and suggested fix would not apply). The null-coalescing read `$request->field ?? ...` stays flagged, since it reads through `__get` and is not in that context.

## Examples

```php
// Bad. Magic read off the raw input bag through Request::__get.
$email = $this->email;       // inside a FormRequest
$name  = $request->name;     // controller holding a FormRequest
```

```php
// Good. Explicit, contract-respecting accessors.
$email = $this->validated('email');
$name  = $request->safe()->name;
$raw   = $request->input('name');
```

## How to fix

Replace the magic read with `validated('field')` for the validated value, `safe()->field` for a validated-input container, or `input('field')` for the raw value when that is genuinely what you want. Because every flagged field has a presence-guaranteeing rule, `validated('field')` is always available.

Alternatively, declare the field with an `@property` annotation (or a real typed property) when you deliberately want it on the class. That opts the read out of the rule.
