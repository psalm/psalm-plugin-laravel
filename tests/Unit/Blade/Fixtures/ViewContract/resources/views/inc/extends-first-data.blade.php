<?php /** @var int $heading */ ?>
@extendsFirst(['inc.missing', 'inc.card'], ['title' => $heading])
@section('body')
    <p>{{ $heading }}</p>
@endsection
