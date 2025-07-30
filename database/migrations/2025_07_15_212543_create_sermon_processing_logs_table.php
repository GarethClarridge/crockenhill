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
    Schema::create('sermon_processing_logs', function (Blueprint $table) {
      $table->id();
      $table->uuid('processing_id')->unique();
      $table->string('original_filename');
      $table->enum('status', ['pending', 'processing', 'completed', 'failed']);
      $table->string('current_step', 50)->nullable();
      $table->text('error_message')->nullable();
      $table->unsignedInteger('sermon_id')->nullable();
      $table->timestamps();

      $table->foreign('sermon_id')->references('id')->on('sermons')->onDelete('set null');
      $table->index(['status', 'created_at']);
      $table->index('processing_id');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('sermon_processing_logs');
  }
};
