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

$k = random_int(1, 10);
$bySubject = match (is_int($k)) {
    true => 'a',
    default => 'b',
};
echo $tern, $and, $bySubject;
?>
