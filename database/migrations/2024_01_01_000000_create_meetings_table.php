<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMeetingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Keep name for general identification
            $table->string('slug')->unique()->nullable(); // slug from show method
            $table->string('type')->nullable(); // from controller
            $table->text('description')->nullable(); // General description
            $table->string('day')->nullable();
            $table->time('StartTime')->nullable(); // from controller
            $table->time('EndTime')->nullable();   // from controller
            $table->string('location')->nullable();
            $table->string('who')->nullable(); // from controller
            $table->string('LeadersPhone')->nullable(); // from controller
            $table->string('LeadersEmail')->nullable(); // from controller
            $table->string('pictures')->nullable(); // from controller (path or flag)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('meetings');
    }
}
