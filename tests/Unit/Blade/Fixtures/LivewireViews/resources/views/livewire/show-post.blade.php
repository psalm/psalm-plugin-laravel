@php /** @psalm-trace $post $count $tags $untyped $instances $hidden $secret $extra $owned $saved $this */ $saved = $this->save(); $probe = [$post, $count, $tags, $untyped, $instances, $hidden, $secret, $extra, $owned]; @endphp
{{ $post->typo() }}
{{ $this->nope() }}
{{ $this->helper() }}
{{ $this->post->title() }}
