---
title: Blade Template Analysis
nav_order: 8
---

# Blade Template Analysis

With `<blade />`, every `*.blade.php` template is analyzed as if it were a PHP file at its own path. The same `errorLevel`, `<issueHandlers>` (including `<directory name="resources/views"/>` scoping), `{{-- @psalm-suppress X --}}` comments and baseline apply to it. Findings are reported at the template's own file and line.

A template variable you have not declared is `mixed`. Psalm's `Mixed*` issues are never reported in templates. Other findings behave as in PHP, so a few derived from undeclared variables can appear: at `errorLevel` 1 to 3, for example `RiskyTruthyFalsyComparison` on `$x ?? false`; at level 4, rarely, an `UndefinedMagicMethod` after a `mixed` argument was coerced. Declare the variable (see [Declaring view variables](#declaring-view-variables)), or scope an `<issueHandlers>` entry to `resources/views`.

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
* Templates are found under `config('view.paths')` and every `loadViewsFrom()` namespace outside `vendor/`.
* If Blade cannot run (no Blade compiler bound, unwritable cache), it turns itself off with one warning. [`failOnInternalError`](config.md#failoninternalerror) makes that fail the run.

## Onboarding

1. Enable `<blade />` and run Psalm.
2. Run `vendor/bin/psalm-laravel blade:annotate` (`--dry-run` to preview the diff). It writes `@var` declarations from the types your `view()` calls and `@include` directives pass, following `@includeIsolated` chains in the same run. Disagreeing call sites are merged when one type covers them (`?User`, `bool`, `Post|Video`); the pairs it could not merge are printed for you to declare by hand, and so is each call site it could not read. Templates using `@props` or `@aware` are skipped. One run is enough when the including template only forwards the variable; a value passed as an expression, or one the including template also uses, may type more on a rerun ([details](contributing/blade.md#annotating-templates)).
3. Triage the new findings: fix, suppress, or [baseline](#suppressing-and-baselining) them.

## What is checked

Without any `@var`:

* Broken references after a refactor: classes, constants, enum cases, static calls.
* Calls to typed helpers and facades (`route()`, `auth()`, `Str::`, ...).
* Class components and `@props` defaults.
* Plugin rules inside templates, when enabled: [`UnregisteredRouteName`](issues/UnregisteredRouteName.md) ([`findUnregisteredRouteNames`](config.md#findunregisteredroutenames)), [`MissingView`](issues/MissingView.md) on `@include` ([`findMissingViews`](config.md#findmissingviews)), [`MissingTranslation`](issues/MissingTranslation.md) ([`findMissingTranslations`](config.md#findmissingtranslations)).
* Taint from sources inside the template: `{!! request('q') !!}` reports `TaintedHtml`; `{{ }}` and `@json()` are escaped and not flagged. See [Security](security.md).

With a `@var` declaration: type checks on the view data itself, as in PHP (with `@var \App\Models\User $user`, `{{ $user->missing() }}` reports `UndefinedMagicMethod`).

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

* Every declared variable is required, `T|null` and `mixed` included.
* `= null` after the name makes it optional. Write the type as `T|null`; `@var string $x = null` reports `InvalidDocblock`.
* `@props(['size' => 'lg'])` declares too, and a literal default types the body (`string` here).
* Write class names fully qualified with a leading backslash, or the call-site type check is skipped.
* `$errors`, `$slot`, `$attributes`, `$loop` and `$__env` are typed by the plugin; never declare them.

## View data checks

Experimental. Reported as `info` by default (hidden unless `--show-info=true`, never fail a run, not baselined), as `error` with [`<experimental value="true" />`](config.md#experimental) or a `<PluginIssue>` handler:

* [`MissingViewData`](issues/MissingViewData.md): a call site does not pass a required variable.
* [`InvalidViewData`](issues/InvalidViewData.md): a passed value does not satisfy the declared type.
* [`UnusedViewData`](issues/UnusedViewData.md): a call site passes a key the template never reads or declares. An app that overrides the `@include` directive gets false positives; suppress this issue there.

Keys the framework adds at render time count as supplied, and `UnusedViewData` never reports them: a Mailable's public properties (statics included) and `$message`, a `MailMessage`'s `level`, `subject`, `greeting`, `salutation`, `introLines`, `outroLines`, `actionText`, `actionUrl`, `displayableActionUrl` and `$message`, and a Livewire component's public non-static properties in the `view()` or factory view its `render()` returns (a mail view built on the side inside `render()` gets none of them). A Mailable or Livewire property is checked against its declared type, an untyped one is not; a `MailMessage`'s property keys are never type-checked. `$message` is `Illuminate\Mail\Message` on the `view()` path, even when a property, a passed key or an override says otherwise. On a `markdown()` path the data wins instead: a property or passed `message` keeps its own type, and without one a Mailable's `$message` is `Message|TextMessage` (the text pass wraps it) and a `MailMessage`'s is `Message`. A Mailable receiver typed as the base class, a contract, a union or an intersection, and an abstract Mailable or Livewire class, have an unknowable key set, so `MissingViewData` is off for them; the keys the call passes are still type-checked and `UnusedViewData` still runs. Not modeled, so a template reading them can still report `MissingViewData`: a property a Mailable subclass adds beyond the receiver's declared type, `Mailable::buildViewDataUsing()` keys, and Livewire `#[Computed]`. Details: [`MissingViewData`](issues/MissingViewData.md#limitations).

```xml
<issueHandlers>
    <PluginIssue name="MissingViewData" errorLevel="error" />
    <PluginIssue name="UnusedViewData" errorLevel="suppress" />
</issueHandlers>
```

[`BladeCompileError`](issues/BladeCompileError.md) is not experimental: a template that fails to compile is skipped and reported as an error.

## Taint through view data

A value a controller passes to `view()` carries its taint into the template, so `{!! $q !!}` reports `TaintedHtml` on the template line, with or without a `@var` declaration:

```php
return view('search', ['q' => $request->input('q')]);
```

```blade
{!! $q !!} {{-- TaintedHtml; {{ $q }} is escaped and not flagged --}}
```

The trace runs from the request input through the controller to the template line, never through the compiled shadow. It is a normal Psalm issue: silence it with `issueHandlers`, `@psalm-suppress` or a baseline.

* **What flows:** the data array (or `$mergeData`) of `view()`, `View::make()`, `Factory::make()` or `response()->view()`, and `->with('key', $value)` / `->with([...])` on those, keyed by variable name and by the canonical view name (`admin/panel`, `admin.panel` and a `pkg::name` hint are the same view). The array may be a literal, a variable (`$data = [...]; view('x', $data)`) or `compact('q', 't')`. Only the keys that hold request input are tainted: `['t' => $input]` does not taint `$q`, and an array Psalm cannot see into (`$request->all()`) taints every key. Only Laravel's own `view()` helper and factory methods count: a same-named function of yours, or a factory subclass that overrides `make()`/`view()`, is not followed.
* **Template to template:** inside a template, the data of `@include`, `@includeIf`, `@includeWhen`, `@includeUnless`, `@includeFirst` and `@extends` reaches the included view, and so does every variable the includer has in scope, because Blade hands the whole scope over (`@includeIsolated` hands over none). A partial that echoes `{!! $q !!}` reports `TaintedHtml` when `$q` is request input the includer passed, assigned or received itself. The data of an anonymous component tag (`<x-card :q="request('q')" />`) reaches the component view's `{!! $q !!}` raw, while `{{ $attributes }}` stays clean: the attribute bag is escaped on the way.
* **What is skipped:** a dynamic view name, a `->with()` on a view held in a variable (`$v->with('q', $input)`), a `->with($key, $value)` whose key is not a literal, `View::share()`, composers, `Route::view()`, Livewire, mail, and the template directives `@each` (`renderEach()`) and the legacy `@component` / `@slot` form (`startComponent()`), an anonymous component given a bag (`:attributes="new ComponentAttributeBag([...])"`, whose entries become view variables at run time) and `@aware` (values pulled from an ancestor component's data). A template fed only that way reports nothing, and so does a variable it introduces without naming it as a `$name` in its own source (`compact('q')`, `extract()`), or a call whose view name Laravel's finder resolves but the plugin does not normalise (`/search`, `admin//panel`).
* **What over-reports:** a key a later `->with()` overwrites with a safe value (`view('x', ['q' => $input])->with('q', 'safe')`) still reports, because taint over-approximates and never hides a real flow. Silence it at the call. The same holds in a template: with `{{ $q }}` tainted in the includer, `@include('p', ['q' => 'safe'])` still reports `{!! $q !!}` in `p`, because the includer's scope is written as well as the explicit data, though Laravel's explicit data wins at run time. A non-literal `$mergeData` is written whole, so `view('p', ['q' => 'safe'], $merge)` with a tainted `$merge['q']` reports in `p` for the same reason.

[Behaviour reference](contributing/blade.md#taint-through-view-data).

## Taint through class component properties

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
* `--set-baseline` / `--use-baseline` work as for PHP. A template entry matches the trimmed text of the whole flagged line, so editing anything on that line (even a CSS class) reports the finding again.
* A baseline holds one Blade mode: a `--no-blade` run reports the template entries as `UnusedBaselineEntry`.

## Known limits

* **Each template is analyzed alone.** `@include`, `@extends` and components are not followed for typing, and a layout's declarations are not checked at its children's call sites.
* **Undeclared variables are silently `mixed`**, so a typo'd variable name is not caught. A `Mixed*` issue from a declared value (`@var array $data`, then `{{ $data['x']->foo() }}`) is hidden too.
* **Taint from view data follows only the calls listed above.** `View::share()`, composers, `Route::view()`, Livewire, mail views and a variable the includer never names are not connected ([details](#taint-through-view-data)). The HTML context (attribute, script, markup) is not considered.
* **Taint from a class component reaches its own view through its public properties** only from a template tag or `new` in PHP code, and only when `render()` returns one literal view; the flow belongs to the class, not to one tag ([details](#taint-through-class-component-properties)).
* **Component tags can fail to compile** when the standalone compile pass cannot resolve them. The template is skipped with a `BladeCompileError`.
* **Multi-line constructs report on their opening line**: a multi-line `{{ }}`, directive argument list, `<x-...>` tag or heredoc.
* **Dynamic view names** (`view($name)`) are not connected to a template.

Behaviour reference (cache invalidation, compiler-generated code, ambient variables, class components, the full limits list): [contributing/blade.md](contributing/blade.md).
