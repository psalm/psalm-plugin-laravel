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
2. Run `vendor/bin/psalm-laravel blade:annotate` (`--dry-run` to preview the diff). It writes `@var` declarations from the types your `view()` calls and `@include` directives pass. Disagreeing call sites are merged when one type covers them (`?User`, `bool`, `Post|Video`); the pairs it could not merge are printed for you to declare by hand. Templates using `@props` or `@aware` are skipped.
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

* **What flows:** a literal-keyed data array (or `$mergeData`) of `view()`, `View::make()`, `Factory::make()` or `response()->view()`, and `->with('key', $value)` / `->with([...])` on those, keyed by variable name and by the canonical view name (`admin/panel`, `admin.panel` and a `pkg::name` hint are the same view). Only the keys passed are tainted: `['t' => $input]` does not taint `$q`. Only Laravel's own `view()` helper and factory methods count: a same-named function of yours, or a factory subclass that overrides `make()`/`view()`, is not followed.
* **What is skipped:** a dynamic view name, data in a variable, a `->with()` on a view held in a variable (`$v->with('q', $input)`), `View::share()`, composers, `Route::view()`, Livewire, mail, and the explicit data of a template's own `@include`. A template fed only that way reports nothing, and so does one that introduces a variable without naming it (`compact('q')`, `extract()`), or a call whose view name Laravel's finder resolves but the plugin does not normalise (`/search`, `admin//panel`).
* **What over-reports:** a key a later `->with()` overwrites with a safe value (`view('x', ['q' => $input])->with('q', 'safe')`) still reports, because taint over-approximates and never hides a real flow. Silence it at the call.

[Behaviour reference](contributing/blade.md#taint-through-view-data).

## Taint through class component properties

A value given to a class component reaches the `{!! !!}` echoes of the component's own view as tainted data, so `{!! $message !!}` reports `TaintedHtml` on the view line while `{{ $message }}` does not:

```blade
<x-alert :message="request('m')" />
```

```php
final class Alert extends Component
{
    public function __construct(public string $message) {}

    public function render(): View
    {
        return view('components.alert');
    }
}
```

```blade
{!! $message !!} {{-- components/alert.blade.php: TaintedHtml --}}
```

* **What flows in:** the literal-keyed attributes of an `<x-alert>` tag, `@component(Alert::class, ...)`, `Alert::resolve([...])`, `$container->make(Alert::class, [...])` (including `app()->make(...)`) and `new Alert($input)` in PHP code. A data key binds the constructor parameter of the same name, so the value then runs through the constructor body exactly as for `new`: a parameter stored under another property name (`$this->text = $label`) reaches the view, and one the constructor escapes (`e($message)`) or replaces does not. A key that is not a constructor parameter writes nothing.
* **What flows out:** the public, non-static properties of the class that `Component::data()` would hand the view, read in the view whose literal name the class's `render()` returns. Not connected: a `protected` property, a name in a literal `$except` list, a name a public method of the class also takes (the method wins), the names `data()` always ignores, a class whose `render()` names no single literal view, and a class that is not an `Illuminate\View\Component`.
* **`make()` is Laravel's own:** `make()` on a container is followed only while it is still Laravel's (`Illuminate\Container\Container`, `Illuminate\Foundation\Application` or the container contract); a userland override, a non-container `make()`, a named or spread argument, and an array held in a variable are not followed. Inside the data array a literal-array spread is flattened in PHP's order, so a later key overwrites an earlier one; a spread that is not a literal array is not read and keeps the earlier keys.
* **What over-reports:**
  * the flow belongs to the class, not to one tag: one tainted `<x-alert>` anywhere reports every unescaped read of `$message` in the component's view, and a literal-attribute tag of the same class does not clear it;
  * a property declared by a shared base class is one node for every subclass, so a tainted `<x-sib-a>` also reports `<x-sib-b message="literal">`'s view (a property declared in a trait is a node per using class, so unrelated components using the trait do not share it);
  * a non-literal `$except` (a docblock-typed list, a constant) keeps every name;
  * a `data()` override that drops a name is not detected.
* **What is skipped:** public methods, `<x-dynamic-component>`, Livewire, mail, and the helper form `app(Foo::class, [...])`.

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
* **Taint from view data reaches only literal, directly named data.** A data array held in a variable, `compact()`, `View::share()`, composers, `Route::view()`, Livewire, mail views and the data of an `@include` are not connected ([details](#taint-through-view-data)). The HTML context (attribute, script, markup) is not considered.
* **Taint from a class component reaches its own view through its public properties.** Public methods, a component whose `render()` is not one literal view, `app(Foo::class, [...])`, and data a class `resolve()`s through a userland container are not connected; the flow belongs to the class, not to one tag ([details](#taint-through-class-component-properties)).
* **Component tags can fail to compile** when the standalone compile pass cannot resolve them. The template is skipped with a `BladeCompileError`.
* **Multi-line constructs report on their opening line**: a multi-line `{{ }}`, directive argument list, `<x-...>` tag or heredoc.
* **Dynamic view names** (`view($name)`) are not connected to a template.

Behaviour reference (cache invalidation, compiler-generated code, ambient variables, class components, the full limits list): [contributing/blade.md](contributing/blade.md).
