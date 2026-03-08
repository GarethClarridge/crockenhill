<?php

declare(strict_types=1);

use App\Enums\InboundEmailStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_emails', function (Blueprint $table): void {
            $table->id();
            $table->string('message_id', 512)->unique();
            $table->string('from');
            $table->string('subject');
            $table->longText('body_plain')->nullable();
            $table->longText('body_html')->nullable();
            $table->timestamp('received_at');
            $table->enum('status', InboundEmailStatus::values())->default(InboundEmailStatus::PENDING->value);
            $table->json('processing_metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_emails');
    }
};
