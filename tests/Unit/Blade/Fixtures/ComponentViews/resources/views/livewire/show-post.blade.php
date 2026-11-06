@php $limit = self::LIMIT; $helped = $this->helper(); @endphp
@php /** @psalm-trace $this $post $limit $helped */ $probe = [$this, $post, $limit, $helped]; @endphp
{{ $post->typo() }}
@php $this->save(); @endphp
@php $this->sav(); @endphp
{{ $this->total }}
{{ $this->flash('ok') }}
@if ($post) @endif
@php class LocalProbe { public function f(): mixed { return $this->missing; } } @endphp
