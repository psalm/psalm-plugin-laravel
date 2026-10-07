<?php
/** @var string $name */
?>
<div>{{ $name }}</div>
<?php $name ??= ''; ?>
<div>{{ '/** @var string $quoted */' }}</div>
<?php
/** @var string $quoted */
$quoted ??= '';
/** @var string $quoted */
?>
