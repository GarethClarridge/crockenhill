<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Song; // Added import

class ScriptureReference extends Model
{
    use HasFactory;

    protected $table = 'scripture_references';

    /**
     * Get the song that this scripture reference belongs to.
     */
    public function song()
    {
        return $this->belongsTo(Song::class);
    }
}
