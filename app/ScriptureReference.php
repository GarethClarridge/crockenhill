<?php namespace Crockenhill;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScriptureReference extends Model
{
    use HasFactory;
 
    protected $table = 'scripture_references';

    public function song()
    {
        return $this->belongsTo(Song::class);
    }
}