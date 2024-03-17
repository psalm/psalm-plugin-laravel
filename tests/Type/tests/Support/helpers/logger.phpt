--FILE--
<?php declare(strict_types=1);
function args(): null
{
    return logger('this should return void');
}

function no_args(): \Illuminate\Log\LogManager
{
  return logger();
}
?>
--EXPECTF--
