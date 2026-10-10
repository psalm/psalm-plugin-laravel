<div>filler</div>
<?php
/** @var int $n */
/** @var bool $f */
if (match ($n) {
    1 => true,
    default => true,
}) {
    echo 1;
}

$tern = match ($n) {
    1 => true,
    default => true,
} ? 'a' : 'b';

$and = match ($n) {
    1 => true,
    default => true,
} && $f;

$noDefault = match ($n) {
    (is_int($n) ? 1 : 2) => 'a',
    3 => 'b',
};
echo $tern, $and, $noDefault;
?>
