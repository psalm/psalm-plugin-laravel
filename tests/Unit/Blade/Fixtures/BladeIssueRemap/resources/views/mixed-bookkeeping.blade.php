<x-alert>Hi</x-alert>
<x-alert><x-alert>Inner</x-alert></x-alert>
<x-alert
    class="a"
>Multi</x-alert>
@error('name')
    <p>{{ $message }}</p>
@enderror
@session('status')
    <p>status</p>
@endsession
@context('trace')
    <p>trace</p>
@endcontext
