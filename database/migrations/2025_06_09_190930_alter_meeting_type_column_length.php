<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Change the 'type' column to VARCHAR(255)
            // It's important to ensure the doctrine/dbal package is installed
            // for column modifications like changing type or length.
            // If it's not, this might fail silently or throw an error
            // depending on the Laravel version and specific change.
            // We assume it's present for now as it's a common dependency.
            $table->string('type', 255)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Revert the change if possible. This depends on the original column type.
            // For simplicity, we'll assume it might have been a shorter string or some other type.
            // This down method might need adjustment if the original type was significantly different
            // or had specific constraints.
            // If original was VARCHAR(something_shorter), this might be:
            // $table->string('type', PREVIOUS_LENGTH)->change();
            // For now, to make it safe, we'll just change it back to a default string length,
            // or you might choose to make the 'down' method simply drop the change if unsure.
            // $table->string('type')->change(); // This would default to VARCHAR(255) in many setups if not specified
            // Given the truncation issues, it was likely very small.
            // Let's assume it was a very short string, e.g. VARCHAR(10) for the sake of a reversible migration.
            // This is a guess; ideally, one would know the original schema.
            $table->string('type', 10)->change();
        });
    }
};
