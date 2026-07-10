<?php

declare(strict_types=1);

use App\Enums\InboundEmailStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inbound_emails', 'status')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            Schema::table('inbound_emails', function (Blueprint $table): void {
                $table->string('status')->default(InboundEmailStatus::Pending->value)->change();
            });

            return;
        }

        $values = implode("','", InboundEmailStatus::values());

        DB::statement("ALTER TABLE inbound_emails MODIFY COLUMN status ENUM('{$values}') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('inbound_emails', 'status')) {
            return;
        }

        DB::table('inbound_emails')
            ->where('status', InboundEmailStatus::ArchiveEval->value)
            ->update(['status' => InboundEmailStatus::Rejected->value]);

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $values = implode("','", [
            InboundEmailStatus::Pending->value,
            InboundEmailStatus::Processed->value,
            InboundEmailStatus::Failed->value,
            InboundEmailStatus::Rejected->value,
        ]);

        DB::statement("ALTER TABLE inbound_emails MODIFY COLUMN status ENUM('{$values}') NOT NULL DEFAULT 'pending'");
    }
};
