<?php
/** @var string $extractedName */
extract(['extractedName' => 'Acme']);
?>
<title>{{ $extractedName }}</title>
@yield('content')
