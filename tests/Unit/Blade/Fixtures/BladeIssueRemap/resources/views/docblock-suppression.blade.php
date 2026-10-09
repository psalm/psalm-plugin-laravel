@php
$suppressed = /**
 * @return int
 * @psalm-suppress InvalidReturnType, InvalidReturnStatement
 */ function () {
    return "x";
};

/** @return int */
function unsuppressedDocblockReturn() {
    return "unsuppressed";
}
@endphp
