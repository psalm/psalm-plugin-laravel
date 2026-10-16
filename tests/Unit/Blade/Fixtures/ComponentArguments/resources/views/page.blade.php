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
@php $s = \str_repeat('a', \random_int(0, 3)); /** @var array<int, string> $arr */ $arr = []; @endphp
<x-shaped size="{{ $s }}" />
<x-shaped n="3" />
<x-shaped level="2" />
<x-shaped kind="App\View\Components\Plain" />
<x-shaped :items="$arr" />
<x-shaped label="{{ $s }}" />
<x-shaped n="many" />
<x-tpl :values="[1, 2]" />
<x-shaped n="0" />
<x-wrapper-a count="3" /><x-wrapper-b count="4" />
@php /** @var mixed $num */ $num = 3; /** @var array-key $key */ $key = 1; /** @var scalar $sc */ $sc = 1; @endphp
@if (is_numeric($num))
<x-gauge :value="$num" />
@endif
<x-label :text="$key" />
<x-gauge :value="$sc" />
<x-fixed-count count="many" />
<x-label :text="\App\Support\Markers::label()" />
