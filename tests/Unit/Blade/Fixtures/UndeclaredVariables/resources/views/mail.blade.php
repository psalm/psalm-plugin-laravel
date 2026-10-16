<?php /** @var \Illuminate\Mail\Message $message */ ?>
<p>{{ $message->getSymfonyMessage()->getSubject() }} {{ $subject }}</p>
