<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\PlayDate; // Added import
use App\Models\ScriptureReference; // Added import

class Song extends Model
{
    use HasFactory;

    protected $table = 'songs';

    /**
     * Get the play dates for the song.
     */
    public function playDates()
    {
        return $this->hasMany(PlayDate::class, 'song_id');
    }

    /**
     * Get the scripture references for the song.
     */
    public function scriptureReferences()
    {
        return $this->hasMany(ScriptureReference::class, 'song_id');
    }
}
