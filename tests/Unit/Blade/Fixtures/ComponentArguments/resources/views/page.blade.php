<x-alert :count="'many'" />
<x-alert :count="3" />
<x-alert count="3" />
<x-alert count="many" />
<x-alert />
<x-alert :count="3" :type="null" />
<x-alert :count="3" class="mt-4" data-role="note" />
<x-alert :count="$undeclared" />
<x-alert
    title="multi-line"
    :count="'many'"
/>
<x-card :width="'wide'" />
<x-link :previous="new \App\View\Components\Link()" />
<x-link :previous="'back'" />
<x-meter max-value="high" />
<x-meter :max-value="10" />
<x-tags />
<x-tags :tags="5" />
<x-clock />
<x-legacy />
<x-custom :count="'many'" />
<x-label :text="new \Illuminate\Support\HtmlString('x')" />
<x-label :text="5" />
<x-label :text="['x']" />
<x-plain extra="x" />
<x-dynamic-component component="alert" :count="'many'" />
<x-anon :count="'many'" />
@component(App\View\Components\Alert::class, 'alert', ['count' => 'many'])
@endComponentClass
@php $registry = \App\Support\Registry::resolve(['count' => 'many']); @endphp
@php $alert = \App\View\Components\Alert::resolve(['count' => 'many']); @endphp
@php $component = \App\View\Components\Alert::resolve(['count' => 'many']); @endphp
{{-- @psalm-suppress InvalidArgument --}}
<x-alert :count="'many'" />
@php $maybe = \random_int(0, 1) === 1 ? 3 : 'many'; @endphp
<x-alert :count="$maybe" />
<x-tone tone="warning" />
<x-tone tone="info" />
