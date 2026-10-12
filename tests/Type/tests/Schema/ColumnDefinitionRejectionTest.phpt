--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;

// lock()/onDelete()/onUpdate() are stubbed to enforce literal unions the vendor @method tags do not
// (vendor: `string` for onDelete/onUpdate; a 4-literal @method param union is not enforced by Psalm).
// after() is not stubbed: its rejection proves the vendor @method tag is enforced.
// indexed() is not a Laravel modifier (Fluent::__call() silently stores it); the vendor @method tags
// seal the class, so it is reported.
function rejected(ColumnDefinition $c, ForeignKeyDefinition $f): void {
    $c->lock('bogus');
    $f->lock('bogus');
    $f->onDelete('bogus');
    $f->onUpdate('bogus');
    $c->after(1);
    $c->indexed();
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Schema\ColumnDefinition::lock expects 'default'|'exclusive'|'none'|'shared', but 'bogus' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Schema\ForeignKeyDefinition::lock expects 'default'|'exclusive'|'none'|'shared', but 'bogus' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Schema\ForeignKeyDefinition::onDelete expects 'cascade'|'no action'|'restrict'|'set null', but 'bogus' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Schema\ForeignKeyDefinition::onUpdate expects 'cascade'|'no action'|'restrict'|'set null', but 'bogus' provided
InvalidArgument on line %d: Argument 1 of after expects string, but 1 provided
UndefinedMagicMethod on line %d: Magic method Illuminate\Database\Schema\ColumnDefinition::indexed does not exist
