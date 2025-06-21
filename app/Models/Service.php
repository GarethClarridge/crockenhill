<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Sermon; // Added import

class Service extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Get the sermons associated with this service.
     */
    public function sermons()
    {
        return $this->hasMany(Sermon::class);
    }
}
