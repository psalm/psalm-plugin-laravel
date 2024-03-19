--FILE--
<?php declare(strict_types=1);

class FooController { public function show(): string { return 'foo';} }
class BarController { public function __invoke(): string { return 'foo';} }

action([FooController::class, 'show']);
action(BarController::class);
?>
--EXPECTF--
