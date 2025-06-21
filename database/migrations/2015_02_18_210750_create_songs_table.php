<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSongsTable extends Migration
{
  public function up()
  {
    Schema::create('songs', function (Blueprint $table) {
      $table->increments('id');
      $table->string('praise_number', 5)->nullable();
      $table->string('title', 100);
      $table->string('author', 100)->nullable();
      $table->text('lyrics')->nullable();
      $table->string('copyright', 100)->nullable();
      $table->string('alternative_title', 100)->nullable();
      $table->boolean('current')->default(true);
      $table->text('notes')->nullable();
      $table->enum('major_category', [
        'mc_1', 'mc_2', 'mc_3', 'mc_4', 'mc_5', 'mc_6', 'mc_7', 'mc_8', 'mc_9', 'mc_10', 'mc_11', 'mc_12'
      ])->nullable();
      $table->enum('minor_category', [
        'min_c_1', 'min_c_2', 'min_c_3', 'min_c_4', 'min_c_5', 'min_c_6', 'min_c_7', 'min_c_8', 'min_c_9', 'min_c_10',
        'min_c_11', 'min_c_12', 'min_c_13', 'min_c_14', 'min_c_15', 'min_c_16', 'min_c_17', 'min_c_18', 'min_c_19', 'min_c_20',
        'min_c_21', 'min_c_22', 'min_c_23', 'min_c_24', 'min_c_25', 'min_c_26', 'min_c_27', 'min_c_28', 'min_c_29', 'min_c_30',
        'min_c_31', 'min_c_32', 'min_c_33', 'min_c_34', 'min_c_35', 'min_c_36', 'min_c_37', 'min_c_38', 'min_c_39', 'min_c_40',
        'min_c_41', 'min_c_42', 'min_c_43', 'min_c_44', 'min_c_45', 'min_c_46', 'min_c_47', 'min_c_48', 'min_c_49', 'min_c_50',
        'min_c_51', 'min_c_52', 'min_c_53', 'min_c_54', 'min_c_55', 'min_c_56', 'min_c_57', 'min_c_58', 'min_c_59', 'min_c_60',
        'min_c_61'
      ])->nullable();
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('songs');
  }
}
