<?php /** @var string $greeting */ ?>
<?php /** @var array<string> $introLines */ ?>
<?php /** @var string $actionUrl */ ?>
<?php /** @var string $displayableActionUrl */ ?>
<?php /** @var \Illuminate\Mail\Message $message */ ?>
<p>{{ $greeting }} {{ implode(",", $introLines) }} {{ $actionUrl }} {{ $displayableActionUrl }}</p>
