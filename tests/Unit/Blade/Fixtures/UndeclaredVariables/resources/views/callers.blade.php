@component('partials.badge', ['label' => 'Hello'])
    @slot('header') Heading @endslot
@endcomponent
<ul>@each('partials.row', $rows, 'row')</ul>
