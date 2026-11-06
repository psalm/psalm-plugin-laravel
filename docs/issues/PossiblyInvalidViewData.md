---
title: PossiblyInvalidViewData
parent: Custom Issues
nav_order: 12
---

# PossiblyInvalidViewData

Emitted when the value a call site passes for a Blade template variable only partly satisfies the type that template declares for it in a `@var` docblock. It is the lower-severity sibling of [InvalidViewData](InvalidViewData.md), split the way Psalm separates `PossiblyNullArgument`, `ArgumentTypeCoercion` and `PossiblyInvalidArgument` from `InvalidArgument`. The template can fail during rendering or print the wrong thing for the part of the value that does not fit.

## Example

```blade
{{-- resources/views/member.blade.php --}}
<?php /** @var \App\Models\Member $member */ ?>
<p>{{ $member->name }}</p>
```

```php
view('member', ['member' => $maybeMember]);   // PossiblyInvalidViewData: Member|null
view('member', ['member' => $memberOrId]);    // PossiblyInvalidViewData: Member|int, a union with a member that does not fit
view('member', ['member' => $contract]);      // PossiblyInvalidViewData: an interface passed where a concrete class is declared

view('member', ['member' => $member]);        // OK
```

A value no part of which can fit (`['member' => 123]`, a bare `null`) stays [InvalidViewData](InvalidViewData.md).

## How to fix

1. Narrow the value at the call site (a null check, `findOrFail()`, an `instanceof` check).
2. Widen the template's declaration if the template really handles the other part (`@var Member|null $member`).

## Limitations

The same gates as [InvalidViewData](InvalidViewData.md#limitations) apply, and the check is silent for a value of unknown type: a `mixed`, or a template parameter bounded by `mixed` (`first()` on an unparameterized `Collection`).

The comparison classifies a passed type the way Psalm's own argument check classifies the same inferred type, and what Psalm rejects outright is [InvalidViewData](InvalidViewData.md). For example `collect([...])` of a literal list is not accepted for a `Collection<int, array<string, mixed>>` declaration, in a function call or in a view. A data array has no expected parameter type to infer against, so an expression Psalm types from its parameter in a call (`new Collection([...])`) can still be reported here.

The issue is experimental: the [reporting level](../blade.md#reporting-level) is shared with the other view data issues.
