@foreach ($members as $member)
    <?php /** @var Member $member */ ?>
    <p>{{ $member }}</p>
@endforeach
