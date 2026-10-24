<?php /** @var string $title */ ?>
<?php /** @var int $count */ ?>
<?php /** @var \ViewContractFixture\Thing $thing */ ?>
<?php /** @var int $loose */ ?>
<?php /** @var string $banner */ ?>
<?php /** @var \Illuminate\Mail\Message $message */ ?>
<p>{{ $title }} {{ $count }} {{ $loose }} {{ $banner }} {{ $message->getSymfonyMessage()->getSubject() }}</p>
