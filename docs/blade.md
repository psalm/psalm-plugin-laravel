---
title: Blade Template Analysis
nav_order: 8
---

# Blade Template Analysis

With `<blade />`, every `*.blade.php` template is analyzed as if it were a PHP file at its own path. The same `errorLevel`, `<issueHandlers>` (including `<directory name="resources/views"/>` scoping), `{{-- @psalm-suppress X --}}` comments and baseline apply to it. Findings are reported at the template's own file and line.

## Enabling it

```xml
<plugins>
    <pluginClass class="Psalm\LaravelPlugin\Plugin">
        <blade />
    </pluginClass>
</plugins>
```

* Off by default. `<blade value="false" />` or no element turns it off.
* `vendor/bin/psalm-laravel analyze --blade` / `--no-blade` switches it for one run (command line wins over `psalm.xml`).
* `cacheDir` sets where compiled templates are cached: `<blade cacheDir="build/blade-shadows" />`. The default sits in Psalm's cache directory, outside your project. A directory inside the project must be gitignored and kept out of `<projectFiles>`.
* Keep `resources/views` out of `<projectFiles>` too. Psalm would scan the raw `.blade.php` files as PHP in addition to their compiled form, so a raw PHP declaration in a template (`<?php class X {} ?>`) is analyzed twice and reports `DuplicateClass`.
* Templates are found under `config('view.paths')` and every `loadViewsFrom()` namespace outside `vendor/`.
* If Blade cannot run (no Blade compiler bound, unwritable cache), it turns itself off with one warning. [`failOnInternalError`](config.md#failoninternalerror) makes that fail the run.

Onboarding: enable `<blade />`, run [`blade:annotate`](#annotating-templates) to write the missing declarations, then triage the new findings: fix, suppress, or [baseline](#suppressing-and-baselining) them.

## What is checked

Without any `@var`:

* Broken references after a refactor: classes, constants, enum cases, static calls.
* Calls to typed helpers and facades (`route()`, `auth()`, `Str::`, ...).
* Class components and `@props` defaults.
* Plugin rules inside templates, when enabled: [`UnregisteredRouteName`](issues/UnregisteredRouteName.md) ([`findUnregisteredRouteNames`](config.md#findunregisteredroutenames)), [`MissingView`](issues/MissingView.md) on `@include` ([`findMissingViews`](config.md#findmissingviews)), [`MissingTranslation`](issues/MissingTranslation.md) ([`findMissingTranslations`](config.md#findmissingtranslations)).
* Taint from sources inside the template: `{!! request('q') !!}` reports `TaintedHtml`; `{{ }}` and `@json()` are escaped and not flagged. See [Security](security.md).

With a `@var` declaration: type checks on the view data itself, as in PHP (with `@var \App\Models\User $user`, `{{ $user->missing() }}` reports `UndefinedMagicMethod`).

A variable you have not declared is `mixed`, so a typo'd variable name is not caught. Psalm's `Mixed*` issues are never reported in templates, declared values included (`@var array $data`, then `{{ $data['x']->foo() }}`). Other findings behave as in PHP, so a few derived from undeclared variables can appear: at `errorLevel` 1 to 3, for example `RiskyTruthyFalsyComparison` on `$x ?? false`; at level 4, rarely, an `UndefinedMagicMethod` after a `mixed` argument was coerced. Declare the variable (see below), or scope an `<issueHandlers>` entry to `resources/views`.

## Declaring view variables

Declare variables in a `<?php` or `@php` docblock at the top of the template. Psalm types the template body from it, and the [view data checks](#view-data-checks) compare call sites against it:

```blade
<?php
/**
 * @var \App\Models\User $user
 * @var string|null $subtitle = null
 */
?>
```

* Every declared variable is required, `T|null` and `mixed` included (a nullable type means "pass `null` explicitly").
* `= null` after the name makes it optional. Write the type as `T|null`; `@var string $x = null` reports `InvalidDocblock`.
* `@props(['size' => 'lg'])` declares too, and a literal default types the body (`string` here) and makes the entry optional.
* Write class names fully qualified with a leading backslash, or the call-site type check is skipped.
* Never declare the names Blade supplies (`$errors`, `$slot`, `$attributes`, `$loop`, `$__env`, which the plugin types) or the ones the template binds itself (a `@foreach` alias, an assignment target). Names the framework supplies to every view (`$app`, anything a provider `share()`d at boot) are not declarations either.

## Annotating templates

`psalm-laravel blade:annotate` writes the missing `@var` declarations as native docblock lines, taking each type from the `view()` calls and include directives that render the template:

```bash
vendor/bin/psalm-laravel blade:annotate            # write them, and print what changed as a coloured unified diff
vendor/bin/psalm-laravel blade:annotate --dry-run  # print the diff only, write nothing
vendor/bin/psalm-laravel blade:annotate --no-diffs # list the changed templates instead of the diff
```

* `--no-diffs` combines with `--dry-run`. Flags the command does not own (`-c psalm.xml`, `--threads`) are forwarded to the Psalm run it starts, which is why it is not called `--diff` (Psalm's own flag).
* It analyzes the whole project and refuses `-f` or a bare path: agreement between call sites is a claim about all of them. Scanning uses your thread count, analysis stays in one process, and a run that ends up forked anyway refuses to write.
* Blade analysis is on for the run whether or not your config enables it; if it disables itself, the command fails and names the reason. Taint analysis is skipped. Psalm's own report is not printed (only the annotated list or diff and the names left unknown), and is shown if the pass never runs.
* Only this command writes to templates. A plain `vendor/bin/psalm` run never does, and a stray `PSALM_LARAVEL_BLADE_ANNOTATE` in the environment cannot arm it.

What it declares, per variable the template reads:

* The type itself, when at least one call site was resolved, all resolved call sites agree, and none leaves the data set open (a spread, a dynamic key, a `$mergeData`) or is a rendering shape the plugin cannot read (`$view = view(...)`, `view(...)->render()`, a `view(...)` passed as an argument, a chain with a method the plugin does not model). Literal precision is dropped (`'draft'` and `'published'` declare `string`) and `int<min, max>` is written `int`.
* Call sites that disagree are merged when one declaration covers them: nullability only (`?User`), one scalar kind (`bool`), up to three classes (`Post|Video`, `Collection<int, Post>|Collection<int, Video>`), never widened to a common parent, or arrays when one call site's array type covers the others (`[]` and `['a', 'b']` beside `list<string>` declare `list<string>`). A `mixed` call site, two scalar kinds, arrays only a new combined shape would cover, or an array beside anything else is not merged: the view, variable and every distinct type are listed under "Undeclared, call sites disagree" (dry run included).
* A variable some closed call site renders the template without is declared `T|null $x = null`.
* Include directives are call sites, typed with what the variable holds at the directive. `@includeIsolated('view', [...])` counts like `view()`. `@include`, `@includeIf`, `@includeWhen`, `@includeFirst` and `@extends` (they hand over the including scope), data the plugin cannot read (`@each` and its empty view, a data variable) and rendering as a component (`@component`, an anonymous `<x-...>` tag) leave the view's data set open. `cards/item` and `cards.item` are the same view.

What it leaves alone:

* A variable the template already declares in a raw `@var` docblock (multi-line types included). Existing declarations are never narrowed or rewritten, so a rerun over an annotated template is a no-op.
* A name the template binds itself: a `@foreach` alias (`$items` is declared, `$item` is not), list destructuring, an assignment, a closure or arrow-function parameter, a `catch` variable, `static`/`global`. A `use ($x)` clause reads the enclosing `$x`, so it is not one of them.
* Component templates (`@props`, `@aware`): skipped whole, not annotated in part.
* Types it cannot write faithfully: `mixed` (a declaration would add no type yet make the variable required at every call site), a type with a generic's template parameter, a type containing `*/`, a type with an array shape key that spells `@verbatim`, `@endverbatim`, `@php` or `@endphp` (Blade pairs those across the whole file before it sets the leading `<?php ?>` block aside, so the key would swallow template text up to the next closer). They are listed per template under "Undeclared, type unknown", so the output doubles as a list of names to declare by hand. A template with only such names gets no docblock.
* Variables of a template with a call site that cannot be read: listed under "Undeclared, a call site could not be read" with the first such call site (`path:line`). Rewrite it as a plain `return view('name', [...])` or `@includeIsolated('name', [...])` and rerun. An unreadable expression marks every literal view name inside it unreadable for the whole run. A `view()` that is an arrow function's return is read on its own even when the outer chain is declined; a declined chain in a callback, or one that could add data to the returned view (`with...()` including magic ones, `nest()`, an offset or property assignment, a method with a non-literal name: `(fn () => view('qr', [...]))()->with('text', 42)`), still counts as unreadable. A view stored or returned from a callback and changed by a later statement is not seen.

Everything outside the inserted lines is byte for byte identical, line endings and BOM included, and the rendered output does not change. The lines go, in order of preference:

1. Into the first doc comment of the `<?php` block the file opens with (top level of the block, not a function, class or closure docblock), before its closing `*/`, indented and line-ended like it, so the template keeps a single docblock. A docblock carrying `@psalm-ignore-var` is skipped: Psalm drops its `@var` tags, so lines appended there would never type the template.
2. In a new `<?php /** ... */ ?>` block directly after that leading block, so a `declare(strict_types=1);` stays first (a leading block that never closes gets a plain docblock after its open tag instead, unless the docblock ignores `@var` tags, when the template is reported as skipped).
3. In a new `<?php /** ... */ ?>` block at the very top of the file, after any BOM.

A one-line header docblock (`<?php /** Header. */ ?>`) is opened out, so the diff modifies that line.

Reruns and manual correction:

* One run is enough along a chain of `@includeIsolated` directives where the including template only forwards the variable (`['member' => $member]`): the child takes the type the including template is declared with in the same run, `|null` when that is declared `= null`. Anything else leaves the child to a rerun: the variable used anywhere else in the including template (echoed, tested in a condition or loop, `isset()`, a receiver, a function argument, assigned or bound by reference, used inside a function or closure the template declares), an expression (`$member->team`, a call), a name the including template binds itself, an include whose data also spreads another array or has a computed key, or a template that may write variables by name (`extract`, `call_user_func`, a computed callee, `use function`, `include`, `require`, `eval`, several namespace blocks). A cycle of includes, a parent whose call sites disagree and a parent that reads names it cannot list leave the child undeclared however often the command runs.
* Array shapes are written as inferred: one call site passing `['a', 'b']` declares `list{string, string}`, and a later call site passing three elements reports a type error on correct code. Widen such a declaration by hand, or delete it and rerun once both call sites exist.
* Annotating changes what the other checks see. Expect new findings on the next run, including [`MissingViewData`](issues/MissingViewData.md) at a call site the annotator could not prove closed that omits a variable the template reads conditionally (`{{ $flag ?? false }}`): add `= null` to the declaration, drop it, or pass it at the call site.

## View data checks

Experimental. Three issues compare `view()` call sites with the declarations above:

* [`MissingViewData`](issues/MissingViewData.md): a call site does not pass a required variable.
* [`InvalidViewData`](issues/InvalidViewData.md): a passed value does not satisfy the declared type.
* [`UnusedViewData`](issues/UnusedViewData.md): a call site passes a key the template never reads or declares. An app that overrides the `@include` directive gets false positives; suppress this issue there.

[`BladeCompileError`](issues/BladeCompileError.md) is not experimental: a template that fails to compile is skipped and reported as an error.

### Reporting level

The three issues run whenever Blade analysis is on. Without an explicit issue handler each is `info`: hidden unless `--show-info=true`, never failing a run, not baselined. Promote all three to `error` with [`<experimental value="true" />`](config.md#experimental):

```xml
<pluginClass class="Psalm\LaravelPlugin\Plugin">
    <blade />
    <experimental value="true" />
</pluginClass>
```

Or set them one by one, or suppress one application wide:

```xml
<issueHandlers>
    <PluginIssue name="MissingViewData" errorLevel="error" />
    <PluginIssue name="UnusedViewData" errorLevel="suppress" />
</issueHandlers>
```

An explicit `<PluginIssue>` entry owns that issue's complete reporting policy, regardless of `<experimental>`. To suppress one call site, put `/** @psalm-suppress MissingViewData */` (or the other name) on the statement.

### Supported call sites

A call site is checked only when its view name is a string literal naming an analyzed template (not a namespace hint such as `pkg::view` under your vendor directory, or an unknown namespace), the rendering expression is the whole of an expression or `return` statement, and its chain is one the plugin models:

* `view()`, `Factory::make()`, `response()->view()`, `Mailable::view()` / `markdown()`, `MailMessage`'s equivalents, and any number of `with()` / `withErrors()` calls on top of them. `with()` is read the way Laravel dispatches it, on `is_array($key)` rather than on the argument count, except on a `MailMessage` chain, where `with()` appends a notification line and binds no template data.
* Only literal string keys are used. A spread in the data array, a dynamic `with()` key, a `$mergeData` argument, or a data argument that is not a single sealed keyed array leaves the supplied key set open: `MissingViewData` declines, `InvalidViewData` still checks the keys it can see.
* In a template, `@includeIsolated('profile', [...])` and `@includeIsolated('profile')` (no data) are checked like `view()`. An ordinary `@include` never reports a missing variable, because the included template also inherits the including one's scope. `InvalidViewData` checks the keys a literal data array passes to `@include`, `@includeIf`, `@includeWhen`, `@includeUnless`, `@includeFirst` and `@extendsFirst` (against the first candidate that exists), `@includeIsolated` and `@extends`. A key read or declared by any template reached through `@include` or `@extends` counts as used for `UnusedViewData`. See [Included templates](contributing/blade.md#included-templates).

### Framework-supplied data

Keys the framework adds at render time count as supplied. They are never "passed", so `UnusedViewData` ignores them. `InvalidViewData` checks a property against its declared type; an untyped property is `mixed` and exempt.

* **Class component** (an `Illuminate\View\Component` subclass): Laravel merges `Component::data()` into the view data: every public non-static property, every public method minus `ignoredMethods()`, and `$attributes`, whatever the `render()` passes (`['component' => $this]`). `MissingViewData` always skips a `render()` call, because a caller can chain `->with([...])` on its result. In another method of the class it still runs, unless the class overrides `data()`, which makes the supplied set open.
* **Mailable** (`$this->view()` / `->markdown()`, or `$mailable->view()` on a receiver typed as one `Mailable` subclass): every public property the subclass declares (promoted, defaulted or assigned, static ones included), plus `$message`, `mailer` and `__laravel_mailable` (`mixed`). A property the call also passes by name is `mixed`, since the passed value can win at run time. A receiver typed as `Mailable` itself, a contract, a union or an intersection, an abstract Mailable, and a Mailable overriding `buildViewData()` or `additionalMessageData()` have an open key set: `MissingViewData` is off, the passed keys are still type-checked (they are `mixed` under an override) and `UnusedViewData` still runs.
* **MailMessage** (`(new MailMessage)->view()` / `->markdown()`): `level`, `subject`, `greeting`, `salutation`, `introLines`, `outroLines`, `actionText`, `actionUrl`, `displayableActionUrl`, `$message`, `mailer` and the `__laravel_notification*` keys. A key the call passes wins over its own entry. The property keys are `mixed` (Laravel's docblocks are coarser than the real values), so they are never type-checked; `displayableActionUrl` is a `string`. A subclass overriding `data()` or `toArray()` has an open key set (`MissingViewData` off, passed keys still type-checked).
* **Livewire** (`view()` or `View::make()` inside `render()` of a `Livewire\Component` subclass): every public non-static property the component declares, in the view `render()` returns only (a mail view built on the side inside `render()` gets none). An abstract component has an open key set.

`$message` is typed by path, because `Mailer::send()` sets it after merging the data on the `view()` path, while the markdown renderers merge the data over it:

* `view()` (Mailable or MailMessage): always `Illuminate\Mail\Message`, whatever a property, a passed key or an override says.
* Mailable `markdown()`: a property named `message` wins, then a passed `message`, each with its own type. Otherwise `mixed` when the data is not fully known or an override is present, else `Message|TextMessage` (the text pass wraps it in `TextMessage`, not a `Message` subclass).
* MailMessage `markdown()`: a passed `message` wins with its own type. Otherwise `mixed` when the data is not fully known, else `Illuminate\Mail\Message`.

Not modeled, so a template reading them can still report `MissingViewData`: a property a Mailable subclass adds beyond the receiver's declared type, `Mailable::buildViewDataUsing()` keys, and Livewire `#[Computed]`.

### Composers and creators

A composer or creator registered for the view (exact name or a wildcard such as `admin.*`) can supply any key. A creator runs inside `view()` (`Factory::make()`), after the data array is set but before any chained `->with()`; a composer runs at render time, after the whole call site. The plugin reads the method instead of running it, and `MissingViewData` exempts only the keys it passes. `InvalidViewData` skips those keys too, since the composer or creator may overwrite the passed value (a chained `->with()` beats a creator, so skipping it there is merely conservative).

What is read:

* Class composers and creators: `View::composer('x', Foo::class)`, `'Foo@method'`, `View::creator(...)`, wildcard names included. The method may be inherited or come from a trait. The keys of several composers on one view are unioned.
* Every `$view->with('key', ...)` and `$view->with(['key' => ..., ...])` with string literal keys, chained or not, as a statement of its own.
* `$view->name()`, `$view->getName()` and `$view->getPath()`, which only read the view: a composer may compute a value from them and pass it to `with()`.

When any composer reaching a view cannot be read, the presence and type checks are skipped for the whole view, as if every key were composer-supplied (`UnusedViewData` still runs, so a key only that composer reads can be reported). A composer cannot be read when it:

* is a closure, or a listener registered straight on the event dispatcher for the exact view name (`Event::listen('composing: x', ...)`);
* passes a key that is not a string literal: a variable, `with($array)`, a spread or named arguments;
* uses `$view` any other way: passes it on (`$this->decorate($view)`, `parent::compose($view)`), assigns, returns or captures it, uses the result of `with()`, sets a property or offset on it, calls another method on it (`withTitle()`, `nest()`, `getData()`), or calls it through `?->`;
* calls `share()`, `extract()`, `get_defined_vars()`, `func_get_args()` or `func_get_arg()`, or a `compact()` naming `$view` or a name that is not a string literal, uses a variable variable, or calls a method or function through a computed name;
* has no method body the plugin can find.

Wildcard listeners registered straight on the dispatcher (`Event::listen('composing:*', ...)`, `Event::listen('*', ...)`) are ignored: the composer API never registers one, and debugging and monitoring packages such as Ray, Telescope or Debugbar use them to watch every view.

**Accepted residual.** The composer class is read as written. A class the container rebinds, extends or hooks before resolving, a view factory or dispatcher subclass, a constructor that shares data, a protected method served by `__call()`, data reaching the view through `call_user_func()` or an `include`, and a wildcard dispatcher listener that adds view data are not modelled. If the keys the view really receives differ from what the composer's method shows, the call site reports; fix it with `= null` on the declaration or a suppression.

**Composers registered after boot.** Only composers registered while the application boots (service providers) are read. One registered later, in a middleware or a controller, is invisible: a view whose only composer is registered at runtime is checked as if it had none, and a view with a boot composer plus a runtime one is checked against the boot composer's keys only. A `share()` inside a method the composer calls, or inside a dependency it receives, is not seen either. Variables bound by `@inject('metrics', 'App\Services\Metrics')` in the template resolve from the container, not from the data array, and are not seen either.

## Security coverage

A value a controller passes to `view()` carries its taint into the template, so `{!! $q !!}` reports `TaintedHtml` on the template line, with or without a `@var` declaration. The HTML context (attribute, script, markup) is not considered.

### Taint through view data

```php
return view('search', ['q' => $request->input('q')]);
```

```blade
{!! $q !!} {{-- TaintedHtml; {{ $q }} is escaped and not flagged --}}
```

The trace runs from the request input through the controller to the template line, never through the compiled shadow.

* **What flows:** the data array (or `$mergeData`) of `view()`, `View::make()`, `Factory::make()` or `response()->view()`, and `->with('key', $value)` / `->with([...])` on those, keyed by variable name and by the canonical view name (`admin/panel`, `admin.panel` and a `pkg::name` hint are the same view). The array may be a literal, a variable (`$data = [...]; view('x', $data)`) or `compact('q', 't')`. Only the keys that hold request input are tainted: `['t' => $input]` does not taint `$q`, and an array Psalm cannot see into (`$request->all()`) taints every key. Only Laravel's own `view()` helper and factory methods count: a same-named function of yours, or a factory subclass that overrides `make()`/`view()`, is not followed.
* **Template to template:** inside a template, the data of `@include`, `@includeIf`, `@includeWhen`, `@includeUnless`, `@includeFirst` and `@extends` reaches the included view, and so does every variable the includer has in scope, because Blade hands the whole scope over (`@includeIsolated` hands over none). A partial that echoes `{!! $q !!}` reports `TaintedHtml` when `$q` is request input the includer passed, assigned or received itself. The data of an anonymous component tag (`<x-card :q="request('q')" />`) reaches the component view's `{!! $q !!}` raw, while `{{ $attributes }}` stays clean: the attribute bag is escaped on the way.
* **What is skipped:** a dynamic view name, a `->with()` on a view held in a variable (`$v->with('q', $input)`), a `->with($key, $value)` whose key is not a literal, `View::share()`, composers, `Route::view()`, Livewire, mail, and the template directives `@each` (`renderEach()`) and the legacy `@component` / `@slot` form (`startComponent()`), an anonymous component given a bag (`:attributes="new ComponentAttributeBag([...])"`, whose entries become view variables at run time) and `@aware` (values pulled from an ancestor component's data). A template fed only that way reports nothing, and so does a variable it introduces without naming it as a `$name` in its own source (`compact('q')`, `extract()`), or a call whose view name Laravel's finder resolves but the plugin does not normalise (`/search`, `admin//panel`).
* **What over-reports:** a key a later `->with()` overwrites with a safe value (`view('x', ['q' => $input])->with('q', 'safe')`) still reports, because taint over-approximates and never hides a real flow. Silence it at the call. The same holds in a template: with `{{ $q }}` tainted in the includer, `@include('p', ['q' => 'safe'])` still reports `{!! $q !!}` in `p`, because the includer's scope is written as well as the explicit data, though Laravel's explicit data wins at run time. A non-literal `$mergeData` is written whole, so `view('p', ['q' => 'safe'], $merge)` with a tainted `$merge['q']` reports in `p` for the same reason.

[Behaviour reference](contributing/blade.md#taint-through-view-data).

### Taint through class component properties

A value given to a class component reaches the unescaped echoes of the component's own view: with `<x-alert :message="request('m')" />` and `public function __construct(public string $message) {}` on a class whose `render()` returns `view('components.alert')`, `{!! $message !!}` in that view reports `TaintedHtml` and `{{ $message }}` does not.

* **What flows in:** the literal-keyed attributes of an `<x-alert>` tag or `@component(Alert::class, ...)` in a template, and `new Alert($input)` in PHP code. A key binds the constructor parameter of that name, so the constructor body applies: a value it escapes (`e($message)`) or replaces stays clean.
* **What flows out:** the public, non-static properties `Component::data()` hands the view, in the view whose literal name `render()` returns (whatever data `render()` passes along). Not connected: `protected` and static properties, names in a literal `$except`, the names `data()` ignores, and `$slot`, which the rendered slot replaces.
* **What over-reports:** the flow belongs to the class, not to a tag, so one tainted `<x-alert>` reports every `{!! $message !!}` in its view. A property of a shared base class is one node for every subclass, and so is a constructor declared in a trait (Psalm keys its parameters by the trait), so a tainted tag of one component using it reports the raw echoes in the views of the others too. A public method sharing a property's name, a non-literal `$except` and a `data()` override are not detected.
* **What is skipped:** `Alert::resolve([...])` and `app()->make(Alert::class, [...])` in PHP code, public methods as view data, `<x-dynamic-component>`, Livewire and mail.

[Behaviour reference](contributing/blade.md#taint-through-class-component-properties).

## Suppressing and baselining

```blade
{{-- @psalm-suppress UndefinedPropertyFetch --}}
<p>{{ $user->nmae }}</p>
```

```xml
<issueHandlers>
    <RiskyTruthyFalsyComparison>
        <errorLevel type="suppress">
            <directory name="resources/views" />
        </errorLevel>
    </RiskyTruthyFalsyComparison>
</issueHandlers>
```

* Do not list templates in `<ignoreFiles>`: they are still analyzed, and every finding is silently dropped.
* The [view data issues](#reporting-level) are suppressed at the call site, not in the template.
* `--set-baseline` / `--use-baseline` work as for PHP. A template entry matches the trimmed text of the whole flagged line, so editing anything on that line (even a CSS class) reports the finding again.
* A baseline holds one Blade mode: a `--no-blade` run reports the template entries as `UnusedBaselineEntry`.

## Known limits

* **Each template is analyzed alone.** `@include`, `@extends` and components are not followed for typing, and a layout's declarations are not checked at its children's call sites.
* **Component tags can fail to compile** when the standalone compile pass cannot resolve them. The template is skipped with a `BladeCompileError`.
* **Multi-line constructs report on their opening line**: a multi-line `{{ }}`, directive argument list, `<x-...>` tag or heredoc.
* **Dynamic view names** (`view($name)`) are not connected to a template.

Behaviour reference (cache invalidation, compiler-generated code, ambient variables, class components, the full limits list): [contributing/blade.md](contributing/blade.md).
