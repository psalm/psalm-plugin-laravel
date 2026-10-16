@php /** @psalm-trace $post $count $tags $untyped $instances $hidden $secret $extra $owned $saved $this */ $saved = $this->save(); $probe = [$post, $count, $tags, $untyped, $instances, $hidden, $secret, $extra, $owned]; @endphp
{{ $post->typo() }}
{{ $this->nope() }}
{{ $this->helper() }}
{{ $this->post->title() }}
{{ $this->total }}
@php $this->rename('x'); @endphp
@php $other = new \App\Livewire\ShowPost(); @endphp
{{ $other->total }} {{ $other->nope() }}
@php class LocalProbe { public function read(): mixed { return $this->missing; } } @endphp
{{ $this->{'braced'} }}
{{ ($this)->wrapped }}
{{ $this /* c */ ->commented }}
{{ ($this)->wrappedCall() }}
{{ $this->{'bracedCall'}() }}
{{ $this /* c */ ->commentedCall() }}
