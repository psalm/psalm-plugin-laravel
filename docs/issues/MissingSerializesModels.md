---
title: MissingSerializesModels
parent: Custom Issues
nav_order: 12
---

# MissingSerializesModels

Emitted when a class implementing `Illuminate\Contracts\Queue\ShouldQueue` holds an Eloquent model in a property, and `Illuminate\Queue\SerializesModels` is not reachable through its trait or parent chain.

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

The trait is nearly always inherited rather than used directly, so the check resolves the complete trait closure (traits used by traits) of the class and of every ancestor. These are all silent:

- classes using `Illuminate\Foundation\Queue\Queueable`, which `make:job` scaffolds since Laravel 11 (it is `use Dispatchable, InteractsWithQueue, QueueableByBus, SerializesModels;`)
- classes extending `Illuminate\Notifications\Notification`, which uses the trait directly
- classes whose parent, or a trait of a trait, brings the trait in
- abstract classes, which may leave the trait to their children
- static properties, which `SerializesModels::__serialize()` skips as well
- inherited properties, reported against the class that declares them rather than every subclass

## How to fix

Add `use SerializesModels;` to the queued class, or replace a hand-assembled trait list with `Illuminate\Foundation\Queue\Queueable`.

If a property deliberately holds a detached model that must survive as-is, suppress the issue at that property with `@psalm-suppress MissingSerializesModels` and say why in a comment.
