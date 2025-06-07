<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSermonsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sermons', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('filename');
            $table->string('filetype')->nullable(); // From seeder
            $table->date('date');
            $table->string('service'); // e.g., morning, evening
            $table->string('slug')->unique();
            $table->string('series')->nullable();
            $table->string('reference')->nullable();
            $table->string('preacher')->nullable();
            $table->text('points')->nullable();
            // No timestamps as per the model (public $timestamps = false;)
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sermons');
    }
}
