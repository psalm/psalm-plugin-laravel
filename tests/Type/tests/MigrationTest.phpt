--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

// Named-class form (legacy; still supported).
class CreateExampleTable extends Migration
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        //
    }

    /**
     * Reverse the migrations.
     * @return void
     */
    public function down()
    {
        //
    }
}

// Anonymous-class form (Laravel 11+ default emitted by `php artisan make:migration`).
return new class extends Migration {
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        //
    }

    /**
     * Reverse the migrations.
     * @return void
     */
    public function down()
    {
        //
    }
};
?>
--EXPECTF--
