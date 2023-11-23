<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeCategoriesToEnumSongsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
      Schema::table('songs', function (Blueprint $table) {
        $table->enum('major_category_enum', [ "Psalms",
                                              "Approaching God",
                                              "Children’s",
                                              "Christ’s Lordship over all of life",
                                              "The Bible",
                                              "The Christian life",
                                              "The church",
                                              "The Father",
                                              "The future",
                                              "The gospel",
                                              "The Holy Spirit",
                                              "The Son"])->nullable();
        $table->enum('minor_category_enum', [ "The eternal Trinity",
                                              "Adoration and thanksgiving",
                                              "Creator and sustainer",
                                              "Morning and evening",
                                              "The Lord’s Day",
                                              "Beginning and ending of the year",
                                              "His character",
                                              "His providence",
                                              "His love",
                                              "His covenant",
                                              "His name and praise",
                                              "His birth and childhood",
                                              "His life and ministry",
                                              "His suffering and death",
                                              "His resurrection",
                                              "His ascension and reign",
                                              "His priesthood and intercession",
                                              "His return in glory",
                                              "His person and power",
                                              "His presence in the church",
                                              "His work in revival",
                                              "Authority and sufficiency",
                                              "Enjoyment and obedience",
                                              "Character and privileges",
                                              "Fellowship",
                                              "Gifts and ministries",
                                              "The life of prayer",
                                              "Evangelism and mission",
                                              "Baptism",
                                              "The Lord’s Supper",
                                              "Invitation and warning",
                                              "Crying out for God",
                                              "New birth and new life",
                                              "Repentance and faith",
                                              "Union with Christ",
                                              "Love for Christ",
                                              "Freedom in Christ",
                                              "Submission and trust",
                                              "Assurance and hope",
                                              "Peace and joy",
                                              "Holiness",
                                              "Humbling and restoration",
                                              "Commitment and obedience",
                                              "Zeal in service",
                                              "Guidance",
                                              "Suffering and trial",
                                              "Spiritual warfare",
                                              "Perseverance",
                                              "Facing death",
                                              "The earth and harvest",
                                              "Christian citizenship",
                                              "Christian marriage",
                                              "Families and children",
                                              "Health and healing",
                                              "Work and leisure",
                                              "Those in need",
                                              "Government and nations",
                                              "The resurrection of the body",
                                              "Judgement and hell",
                                              "Heaven and glory"])->nullable();
      });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
      Schema::table('songs', function (Blueprint $table) {
          $table->dropColumn('major_category_enum');
          $table->dropColumn('minor_category_enum');
      });
    }
}
