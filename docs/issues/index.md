---
title: Custom Issues
nav_order: 4
has_children: true
---

# Custom Issues

The plugin ships advanced Laravel-aware static analysis checks that extend Psalm's built-in diagnostics:

- [NoEnvOutsideConfig](NoEnvOutsideConfig.md) — `env()` called outside the application's config directory
- [InvalidConsoleArgumentName](InvalidConsoleArgumentName.md) — `argument()` references undefined console command argument
- [InvalidConsoleOptionName](InvalidConsoleOptionName.md) — `option()` references undefined console command option
- [MissingView](MissingView.md) — a view name passed to `view()`, `Factory`, `ResponseFactory`, `Router`, `MailMessage`, or `TestResponse` methods references a non-existent Blade template (reported at error levels 1 to 4)
- [MissingTranslation](MissingTranslation.md) — `__()` or `trans()` references an undefined translation key (opt-in)
- [UnconfiguredFilesystemDisk](UnconfiguredFilesystemDisk.md) — `Storage::disk()`/`drive()` references a disk name not present in `filesystems.disks` (reported at error levels 1 to 4)
- [SerializedQueuedModel](SerializedQueuedModel.md) — a queued class holds an Eloquent model in a property without reaching `SerializesModels`, so the whole model is written into the queue payload (reported at error levels 1 to 4)
- [ModelMakeDiscouraged](ModelMakeDiscouraged.md) — `Model::make()` used instead of `new Model()`
- [OctaneIncompatibleBinding](OctaneIncompatibleBinding.md) — `singleton()` closure resolves a request-scoped service such as Request, Session, or Auth (auto-enabled when `laravel/octane` is installed) (**fixable**)
- [PublicModelScope](PublicModelScope.md) — `public` `#[Scope]` Eloquent query scope, whose static call is a runtime fatal (reported at error levels 1 to 4)
- [PublicModelAccessor](PublicModelAccessor.md) — `public` legacy `getXxxAttribute()` / `setXxxAttribute()` accessor or mutator, a pure convention nit (reported at error level 1)
- [ImplicitQueryBuilderCall](ImplicitQueryBuilderCall.md) — a query builder or local scope method called directly on a model instead of through an explicit `query()` entry point (opt-in, **fixable**)
- [UnknownModelAttribute](UnknownModelAttribute.md) — a typo'd key passed to a model's `create()` / `fill()` / `update()`, or to a builder or relation creation call (`Post::query()->create()`, `$user->posts()->createMany()`), that matches no known attribute (reported at error levels 1 to 4)
- [UnresolvableAppendedModelAttribute](UnresolvableAppendedModelAttribute.md) — an Eloquent `$appends` entry with no backing accessor or class cast, a runtime `BadMethodCallException` on `toArray()` / `toJson()`
- [UndefinedModelRelation](UndefinedModelRelation.md) — a relation name passed to `with()`, `load()`, `has()`, `whereHas()`, and similar methods that does not resolve to a relationship on the model
- [MissingViewData](MissingViewData.md): a Blade template declares a variable (`@var` docblock, `@props`) that the `view()` call site rendering it never passes (needs Blade analysis)
- [InvalidViewData](InvalidViewData.md): the value passed for a Blade template variable does not satisfy the type that template declares for it (needs Blade analysis)
- [BladeCompileError](BladeCompileError.md): a template failed to compile and was excluded from analysis (needs Blade analysis)
- [UnusedViewData](UnusedViewData.md): a `view()` call site passes a data key that neither the rendered template nor anything it includes reads or declares (needs Blade analysis)
- [UnregisteredRouteName](UnregisteredRouteName.md) — `route()`, `to_route()`, `URL::route()`, `Redirect::route()`, `redirect()->route()`, or `url()->route()` references a route name that is not registered (opt-in)

Each issue page explains what it detects, why it matters, and how to fix it.

## Fixing issues automatically

Some issues can be fixed by Psalter, Psalm's code-fixing tool. The issues marked **fixable** in the list above can be fixed by running `psalter` with their names; preview the diff first with `--dry-run`:

```bash
./vendor/bin/psalter --issues=ImplicitQueryBuilderCall --dry-run
./vendor/bin/psalter --issues=ImplicitQueryBuilderCall
```

`./vendor/bin/psalter --list-supported-issues` lists the fixable issues. An issue is fixed only while its rule is active: `ImplicitQueryBuilderCall` needs `<reportImplicitQueryBuilderCalls value="true" />`, and `OctaneIncompatibleBinding` needs the Octane rule on (auto-enabled when `laravel/octane` is installed, off with `<findOctaneIncompatibleBinding value="false" />`).

### When Psalter leaves a finding unfixed

A fix is made only when Psalter can show that the rewritten code does what the original did. Otherwise the finding is still reported and left for you to fix by hand. This applies to every fixable issue:

- A suppressed finding (`@psalm-suppress <Issue>`, or `<PluginIssue name="<Issue>" errorLevel="suppress" />` in `psalm.xml`) is not rewritten.
- A finding in a Blade template is reported but never rewritten.
- Any call inside a trait: the trait's one source is shared by every class that uses it, and an edit that is right for one consumer can be wrong for another.

**ImplicitQueryBuilderCall** is left alone for:

- A first-class callable (`User::where(...)`).
- A call through `self::`, `static::` or `parent::`: in an instance method `static::where(...)` runs `$this->__call()` and keeps the instance's connection and table, while `static::query()` builds a fresh model and drops them.
- A named static call such as `User::where(...)` inside a non-static method or closure of a class that is the model, one of its subclasses or one of its ancestors. PHP keeps the object context there, so the call runs `$this->__call()` with the instance's table and connection, while `User::query()` builds a fresh model. The same call in a static method, a static closure, or an unrelated class is rewritten.
- A named static call (`User::where(...)`) on a model whose class chain (ancestors and traits included) declares `__call`, `__callStatic`, `forwardCallTo()` or `query` itself rather than inheriting Eloquent's: the inserted `query()` would skip that code. Subclasses are not looked at here, because `User::where(...)` builds `new static` of the named class.
- An instance call (`$user->where(...)`, `$this->where(...)`) on a model whose class chain declares `__call` or `forwardCallTo()` itself, or that has a subclass Psalm scanned that does so or declares the called method itself (`where()` or a scope's bare name): the object behind the receiver can be that subclass, and the inserted `newQuery()` would skip its `__call()` or its own method. An override of `newQuery()` does not count, since `__call()` and `query()` both reach it.
- A call inside a closure or arrow function whose dispatch reads the closure's binding, whatever it is today: `Closure::call()`, `bind()` and `bindTo()` can rebind it. A named static call such as `User::where(...)` becomes the rebound model's `__call('where')`, and an instance call whose receiver contains `$this` reads the rebound object (`$this->where(...)`, `(clone $this)->where(...)`, `$this->posts()[0]->where(...)`); both are declined in a non-static closure only, since a `static` closure has no `$this`. A receiver that contains `self`, `static` or `parent` (`self::$user->where(...)`) is declined in any closure, `static` ones included, because `Closure::bind($closure, null, Other::class)` changes the class scope. An instance call on a captured model (`$user->where(...)` inside `function () use ($user)`) reads neither and is rewritten.

**OctaneIncompatibleBinding** is renamed only when the receiver's type is exactly `Illuminate\Foundation\Application`, `Illuminate\Contracts\Foundation\Application`, `Illuminate\Container\Container` or `Illuminate\Contracts\Container\Container`. Any other receiver (a container subclass, a union that does not reduce to one of these types, `mixed`) is reported and left as written, because a subclass may give `scoped()` / `scopedIf()` other parameters and the call's named arguments (`singleton(abstract: ..., concrete: ...)`) would then throw after the rename. It is reported too when any class Psalm scanned that extends or implements the receiver's type (a `Container` subclass, for a receiver typed `Container` or its contract) declares `scoped()` or `scopedIf()` itself rather than inheriting Laravel's, since the declared type can hold an instance of it at runtime. Inside a closure or arrow function it is reported when the receiver contains `self`, `static` or `parent` (any closure) or `$this` (a non-static one), because `Closure::bind()` and `call()` can rebind it onto another container; `$this->app->singleton(...)` in a provider method is renamed.

#### Known gaps

Psalter cannot see these at analysis time, so a rewrite can still change behavior in them. Review the diff, or suppress the call:

- `ImplicitQueryBuilderCall`: a relation registered at runtime with `Model::resolveRelationUsing('where', ...)`, under the called method's name: `User::where(...)` then returns that relation, `User::query()->where(...)` returns a builder.
- `ImplicitQueryBuilderCall`: `Model::__call()` sends a method named `through<Relation>` (`throughPosts()` for a `posts()` relation) to `through()` before it forwards anything. For a forwarded scope or custom builder method with such a name, `User::throughPosts(...)` returns a pending through-relationship and `User::query()->throughPosts(...)` runs the scope.
- `ImplicitQueryBuilderCall`: a subclass of the model that Psalm did not scan (vendor code it never loaded, a generated or anonymous class, one built at runtime) and that overrides `__call()` or `forwardCallTo()`, or declares the called method itself. A scanned subclass is checked and keeps the call reported; an unscanned one is invisible, so `$user->where(...)` on an instance of it would skip its code after the rewrite.
- `ImplicitQueryBuilderCall`: a closure whose class scope is rebound (`Closure::bind($fn, null, User::class)`) can make a non-public method of a captured model directly callable, such as a protected `#[Scope]` method. `$user->active()` then no longer goes through `__call()`, while `$user->newQuery()->active()` still does.
- `ImplicitQueryBuilderCall`: a subclass that re-imports a trait method with another visibility (`use ActiveScope { active as public; }`) changes whether PHP reaches `__call()` for that method. The descendant scan compares declarations, not effective visibility.
- `OctaneIncompatibleBinding`: an unscanned subclass (vendor code Psalm never loaded, a generated or anonymous class, one built at runtime) of the receiver's declared container type that overrides `scoped()` or `scopedIf()`. `singleton(abstract: ..., concrete: ...)` on an instance of it would still throw after the rename. A scanned one is checked and keeps the binding reported.
