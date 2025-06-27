<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;
    protected $guarded = [];

    /**
     * Get the service's formatted name.
     *
     * @return string
     */
    public function getFormattedNameAttribute()
    {
        return ucfirst($this->type) . ' Service';
    }
}
