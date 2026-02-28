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
        Schema::create('church_services', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('service');
            $table->string('source');
            $table->string('original_filename')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->json('import_metadata')->nullable();
            $table->timestamps();

            $table->unique(['date', 'service']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('church_services');
    }
};
