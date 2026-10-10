<div>filler</div>
<?php
/** @var bool $flag */
$label = 'x' . match ($flag) {
    true => 'yes',
    false => 'no',
} . 'y';
?>
<p>{{ $label }}</p>
<?php
/** @var int $n */
$m = match ($n) {
    1 => is_string($n) ? 'a' : 'b',
    default => $n === 1 ? 'c' : 'd',
};
echo $m;
if ($flag === true) {
    echo 1;
} elseif ($flag === false) {
    echo 2;
}
?>
