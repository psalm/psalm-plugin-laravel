<?php
/**
 * @var string $bad = null
 * @var string|null $good = null
 */
?>
<?php
/**
 * @var (string|null) $paren = null
 * @var (mixed) $any = null
 * @var 'a|b'|null $literal = null
 */
?>
<?php
/**
 * @psalm-suppress InvalidDocblock
 * @var string $quiet = null
 */
?>
{{-- @psalm-suppress InvalidDocblock --}}
<?php
/**
 * @var string $blockQuiet = null
 */
?>
<p>{{ $bad }}{{ $good }}{{ $paren }}{{ $any }}{{ $literal }}{{ $quiet }}{{ $blockQuiet }}</p>
