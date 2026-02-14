<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('preacher_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preacher_id')->constrained('preachers')->cascadeOnDelete();
            $table->string('alias', 255)->unique();
            $table->timestamps();

            $table->index('preacher_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preacher_aliases');
    }
};
