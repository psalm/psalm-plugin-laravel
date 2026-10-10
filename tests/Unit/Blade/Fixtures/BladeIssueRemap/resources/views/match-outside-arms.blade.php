<div>filler</div>
<?php
/** @var int $n */
/** @var bool $f */
/** @var list<int> $list */
/** @var object $obj */
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

$upperTrue = match (\TRUE) {
    (is_int($n) ? true : false) => 'a',
    $f => 'b',
};

$byClass = match ($obj::class) {
    (is_int($n) ? 'x' : 'y') => 'a',
    'z' => 'b',
};

$byCount = match (count($list)) {
    (is_int($n) ? 1 : 2) => 'a',
    3 => 'b',
};

$byGetClass = match (get_class($obj)) {
    (is_int($n) ? 'x' : 'y') => 'a',
    'z' => 'b',
};
echo $upperTrue, $byClass, $byCount, $byGetClass;
?>
