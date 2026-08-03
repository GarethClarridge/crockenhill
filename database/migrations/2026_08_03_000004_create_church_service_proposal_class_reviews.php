<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_service_proposal_class_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('class_key')->unique();
            $table->string('status', 20);
            $table->text('reason');
            $table->unsignedInteger('seconds_per_decision')->nullable();
            $table->unsignedInteger('marked_by_user_id');
            $table->timestamps();

            $table->foreign('marked_by_user_id', 'proposal_class_reviews_reviewer_fk')
                ->references('id')
                ->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_service_proposal_class_reviews');
    }
};
