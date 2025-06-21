<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayDate extends Model
{
    use HasFactory;

    protected $table = 'play_date';

    public function post()
    {
        return $this->belongsTo('Song');
    }
}
