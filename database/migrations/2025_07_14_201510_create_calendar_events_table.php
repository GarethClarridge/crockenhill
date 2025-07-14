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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('google_event_id')->unique();
            $table->string('meeting_slug')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('speaker')->nullable();
            $table->string('location')->nullable();
            $table->dateTime('start_datetime')->index();
            $table->dateTime('end_datetime');
            $table->string('status')->default('confirmed');
            $table->boolean('is_categorized_automatically')->default(false);
            $table->timestamps();

            // Composite indexes for efficient querying
            $table->index(['meeting_slug', 'start_datetime']);
            $table->index(['start_datetime', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
