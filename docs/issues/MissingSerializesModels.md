---
title: MissingSerializesModels
parent: Custom Issues
nav_order: 6
---

# MissingSerializesModels

Emitted when a class implementing `Illuminate\Contracts\Queue\ShouldQueue` holds an Eloquent model in a property, and `Illuminate\Queue\SerializesModels` is not reachable through its trait or parent chain.

One report per class, on the class declaration, naming the offending properties (the first three of them). The fix is a single `use SerializesModels;`, so a per-property report would be several findings for one edit.

Opt-in. Enable with `findMissingSerializesModels` (see [Configuration](../config.md)).

## Why this is a problem

`SerializesModels::__serialize()` replaces each model with a `ModelIdentifier` and re-resolves it in `__unserialize()`. Without the trait, the whole model goes into the queue payload:

- the worker runs against a snapshot taken at dispatch time, not current data
- payloads grow with the model's attributes and every loaded relation
- a payload written before a schema change can fail to unserialize after it

## Examples

```php
// Bad — the entire Customer, with whatever relations were loaded, is written into the payload
class SendCustomerReport implements ShouldQueue
{
    use Queueable; // Illuminate\Bus\Queueable — no SerializesModels

    public function __construct(public Customer $customer) {}
}
```

```php
// Good — only a ModelIdentifier is serialized, and the worker reloads the row
class SendCustomerReport implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Customer $customer) {}
}
```

## What is not reported

A queued class that reaches the trait at all, directly or indirectly (through a parent, or a trait that itself uses it). That covers `Illuminate\Foundation\Queue\Queueable`, which `make:job` scaffolds since Laravel 11, and `Illuminate\Notifications\Notification`.

Also silent: abstract classes, static properties, and properties inherited from a parent (they count against the class that declares them).

## How to fix

Add `use SerializesModels;` to the queued class, or replace a hand-assembled trait list with `Illuminate\Foundation\Queue\Queueable`.

If a property deliberately holds a detached model that must survive as-is, suppress the issue in the class docblock with `@psalm-suppress MissingSerializesModels` and say why in a comment. The report is class-level, so a property-level suppression does not reach it.
