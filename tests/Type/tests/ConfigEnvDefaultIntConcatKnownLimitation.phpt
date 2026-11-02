--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION: Psalm's concat check ignores ignore_nullable_issues once the operand also has a
 * non-string atomic, so an env value with an int default (`int|null|string`, see the EnvHandler
 * docblock) reports PossiblyInvalidOperand when concatenated.
 * 'port' => env('MAIL_PORT', 2525)
 * @see https://github.com/vimeo/psalm/issues/13031
 */
function concat_env_backed_config_with_int_default(): string
{
    return 'port:' . config('mail.mailers.smtp.port');
}

function concat_env_with_int_default(): string
{
    return 'port:' . env('APP_PORT', 8070);
}
?>
--EXPECTF--
PossiblyInvalidOperand on line %d: Cannot concatenate with a int|null|string
PossiblyInvalidOperand on line %d: Cannot concatenate with a int|null|string
