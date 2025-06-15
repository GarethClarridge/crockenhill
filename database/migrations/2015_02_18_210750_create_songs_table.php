
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
        'Psalms',
        'Approaching God',
        'Children\'s',
        'Christ\'s Lordship over all of life',
        'The Bible',
        'The Christian life',
        'The church',
        'The Father',
        'The future',
        'The gospel',
        'The Holy Spirit',
        'The Son'
      ])->nullable();
      $table->enum('minor_category', [
        'The eternal Trinity',
        'Adoration and thanksgiving',
        'Creator and sustainer',
        'Morning and evening',
        'The Lord\'s Day',
        'Beginning and ending of the year',
        'His character',
        'His providence',
        'His love',
        'His covenant',
        'His name and praise',
        'His birth and childhood',
        'His life and ministry',
        'His suffering and death',
        'His resurrection',
        'His ascension and reign',
        'His priesthood and intercession',
        'His return in glory',
        'His person and power',
        'His presence in the church',
        'His work in revival',
        'Authority and sufficiency',
        'Enjoyment and obedience',
        'Character and privileges',
        'Fellowship',
        'Gifts and ministries',
        'The life of prayer',
        'Evangelism and mission',
        'Baptism',
        'The Lord\'s Supper',
        'Invitation and warning',
        'Crying out for God',
        'New birth and new life',
        'Repentance and faith',
        'Union with Christ',
        'Love for Christ',
        'Freedom in Christ',
        'Submission and trust',
        'Assurance and hope',
        'Peace and joy',
        'Holiness',
        'Humbling and restoration',
        'Commitment and obedience',
        'Zeal in service',
        'Guidance',
        'Suffering and trial',
        'Spiritual warfare',
        'Perseverance',
        'Facing death',
        'The earth and harvest',
        'Christian citizenship',
        'Christian marriage',
        'Families and children',
        'Health and healing',
        'Work and leisure',
        'Those in need',
        'Government and nations',
        'The resurrection of the body',
        'Judgement and hell',
        'Heaven and glory'
      ])->nullable();
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('songs');
  }
}
