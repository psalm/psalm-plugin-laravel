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

$lowerTrue = match (true) {
    (is_int($n) ? true : false) => 'a',
    $f => 'b',
};

$byCallArg = match (count(\array_values($list))) {
    (is_int($n) ? 1 : 2) => 'a',
    3 => 'b',
};

$byNamedClass = match (\stdClass::class) {
    (is_int($n) ? 'x' : 'y') => 'a',
    'z' => 'b',
};

$k = random_int(1, 10);
$s = (string) $k;
/** @var 'long' $expected */
$expected = 'long';
$byDocblock = match ($k) {
    (substr($s, 0, 1) !== $expected ? 1 : 2) => 'a',
    4 => 'b',
};
echo $upperTrue, $byClass, $byCount, $byGetClass, $lowerTrue, $byCallArg, $byNamedClass, $byDocblock;
?>
